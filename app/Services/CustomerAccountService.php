<?php

namespace App\Services;

use App\Mail\CustomerPasswordResetMail;
use App\Mail\CustomerRegistrationMail;
use App\Mail\CustomerVerificationMail;
use App\Models\EngageCustomer;
use App\Models\User;
use App\Models\UserVerification;
use App\Support\ActionJwt;
use App\Support\SessionJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CustomerAccountService
{
    public function __construct(
        private CustomerService $customerService,
        private GhlService $ghlService,
    ) {}

    /**
     * Explicit self-registration (the customer portal's "Register" form) — unlike
     * ensureCustomerAccount() below (a silent side-effect of booking), this always
     * surfaces a clear error for an email that's already taken, rather than
     * quietly no-oping. Always creates the account as role=customer.
     *
     * @return User the (possibly pre-existing, re-sent) customer user
     */
    public function register(array $data): User
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            throw new \InvalidArgumentException('An email address is required to register.');
        }

        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            if (! $existing->hasRole(User::ROLE_CUSTOMER)) {
                throw new \InvalidArgumentException('An account already exists for this email.');
            }

            if ($existing->status === User::STATUS_ACTIVE) {
                throw new \InvalidArgumentException('An account already exists for this email. Please log in or use Forgot Password.');
            }

            if ($existing->isLoginBlocked()) {
                throw new \InvalidArgumentException('This account cannot be registered. Please contact support.');
            }

            // Pending but never finished registering — let them retry cleanly.
            $this->initiateVerification($existing, CustomerRegistrationMail::class);

            return $existing->fresh();
        }

        $locationId = OrganizationLocationResolver::resolveDefaultLocationId();
        $customer = $this->customerService->findOrCreate($data, $locationId, User::createdByLabel(null, $data['name'] ?? ''));

        try {
            $this->ghlService->syncContactToGhl($customer);
        } catch (\Exception $e) {
            Log::error('Lead Connector sync failed for customer registration', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $customerUser = new User;
        $customerUser->name = $data['name'] ?? $customer->name;
        $customerUser->email = $data['email'];
        $customerUser->roles = [User::ROLE_CUSTOMER];
        $customerUser->status = User::STATUS_PENDING;
        $customerUser->customer_id = $customer->id;
        $customerUser->created_by = null; // self-registration
        $customerUser->password = null;
        $customerUser->save();

        $customerUser->attachLocation($locationId);

        $this->initiateVerification($customerUser, CustomerRegistrationMail::class);

        return $customerUser->fresh();
    }

    /**
     * Hook for public booking (and staff/admin customer creation): create a customer
     * User linked to the Customer, or no-op.
     *
     * Outcomes:
     * 1. No matching User email → create role=customer User; sends verification unless $sendEmail is false
     * 2. Existing role=customer → sync customer_id, do not resend email
     * 3. Existing staff/admin/cashier email → conservative no-op (never touch staff login)
     *
     * @param  bool  $sendEmail  false for staff/admin-created customers (CustomerController::store()) —
     *                           the account (and its "Customer" role badge) is still created, just without
     *                           emailing an unsolicited "verify your account" message to someone who didn't
     *                           ask for one. The public booking widget always leaves this true.
     * @param  User|null  $createdBy  Staff/admin who created the account; null for self/public booking.
     */
    public function ensureCustomerAccount(EngageCustomer $customer, array $contactData = [], bool $sendEmail = true, ?User $createdBy = null): void
    {
        $email = strtolower(trim((string) ($contactData['email'] ?? $customer->email ?? '')));

        if ($email === '') {
            return;
        }

        DB::transaction(function () use ($customer, $contactData, $email, $sendEmail, $createdBy) {
            $locationId = $createdBy?->resolveOrganizationLocationId()
                ?? OrganizationLocationResolver::resolveDefaultLocationId();

            $existing = User::whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $customerUser = new User;
                $customerUser->name = $contactData['name'] ?? $customer->name;
                $customerUser->email = $contactData['email'] ?? $customer->email;
                $customerUser->roles = [User::ROLE_CUSTOMER];
                $customerUser->status = User::STATUS_PENDING;
                $customerUser->customer_id = $customer->id;
                $customerUser->created_by = $createdBy?->id;
                $customerUser->password = null;
                $customerUser->save();

                $customerUser->attachLocation($locationId);
                $customer->attachLocation($locationId);

                $this->initiateVerification($customerUser, CustomerVerificationMail::class, $sendEmail);

                return;
            }

            if ($existing->hasRole(User::ROLE_CUSTOMER)) {
                if ($existing->customer_id !== $customer->id) {
                    $existing->customer_id = $customer->id;
                    $existing->save();
                }

                return;
            }

            // Staff/admin/cashier collision: never create or alter that login.
        });
    }

    /**
     * Hook for customer deletion: wipe the linked customer login entirely (revoking its
     * tokens first), so that if the same email is used again it's treated as a brand
     * new signup — a fresh User row, a fresh verification email — rather than silently
     * re-linking to the old (already-verified) account.
     */
    public function deleteCustomerAccount(EngageCustomer $customer): void
    {
        $customerUser = $customer->customerAccount;

        if (! $customerUser) {
            return;
        }

        SessionJwt::revokeAllFor($customerUser);
        $customerUser->delete();
    }

    /**
     * @param  class-string  $mailableClass  CustomerVerificationMail (default — booking/contact-created path)
     *                                       or CustomerRegistrationMail (direct self-registration via /customer/register).
     *                                       Both share the same (User, code, token) constructor signature.
     * @param  bool  $sendEmail  Always generates and stores a fresh verification row regardless — false only
     *                           skips actually emailing it (see ensureCustomerAccount()'s $sendEmail doc).
     */
    public function initiateVerification(User $customerUser, string $mailableClass = CustomerVerificationMail::class, bool $sendEmail = true): void
    {
        if (! $customerUser->email) {
            throw new \InvalidArgumentException('An email address is required to create a customer account.');
        }

        $ttl = (int) config('customer.verification_ttl_minutes');
        [$jwt, $code] = $this->issueActionToken(
            $customerUser,
            UserVerification::TYPE_EMAIL_VERIFICATION,
            $ttl,
            withCode: true,
        );

        if ($customerUser->status !== User::STATUS_ACTIVE) {
            $customerUser->status = User::STATUS_PENDING;
            $customerUser->save();
        }

        if (! $sendEmail) {
            return;
        }

        try {
            Mail::to($customerUser->email)->send(new $mailableClass($customerUser, $code, $jwt));
        } catch (\Throwable $e) {
            Log::error('Customer verification email failed', [
                'user_id' => $customerUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resendVerification(string $email): void
    {
        $customerUser = $this->findCustomerByEmail($email);

        if (! $customerUser || $customerUser->status === User::STATUS_ACTIVE) {
            if ($customerUser?->status === User::STATUS_ACTIVE) {
                throw new \InvalidArgumentException('This account is already verified. Please log in.');
            }

            return;
        }

        if ($customerUser->isLoginBlocked()) {
            throw new \InvalidArgumentException('This account cannot be verified. Please contact support.');
        }

        $this->initiateVerification($customerUser);
    }

    public function verifyCode(string $token, string $code): User
    {
        $verification = $this->findOpenVerification($token, UserVerification::TYPE_EMAIL_VERIFICATION);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $maxAttempts = (int) config('customer.max_verification_attempts');
        if ($verification->attempts >= $maxAttempts) {
            throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
        }

        if (! $verification->code_hash || ! Hash::check($code, $verification->code_hash)) {
            $verification->attempts = ($verification->attempts ?? 0) + 1;
            $verification->save();

            if ($verification->attempts >= $maxAttempts) {
                throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
            }

            throw new \InvalidArgumentException('Incorrect verification code.');
        }

        $verification->verified_at = now();
        $verification->code_hash = null;
        $verification->attempts = 0;
        $verification->save();

        // User stays pending until createPassword() — email proof lives on the verification row.
        return $verification->user->fresh();
    }

    /**
     * @return array{user: User, token: string}
     */
    public function createPassword(string $token, string $password): array
    {
        $verification = $this->findOpenVerification($token, UserVerification::TYPE_EMAIL_VERIFICATION);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if (! $verification->isVerified()) {
            throw new \InvalidArgumentException('Please verify your email before creating a password.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $customerUser = $verification->user;

        if ($customerUser->isLoginBlocked()) {
            throw new \InvalidArgumentException('This account cannot be activated. Please contact support.');
        }

        $this->assertPasswordPolicy($password);

        // Plain assignment — User's `hashed` cast hashes once.
        $customerUser->password = $password;
        $customerUser->status = User::STATUS_ACTIVE;
        $customerUser->save();

        $verification->consumed_at = now();
        $verification->save();

        $plainToken = SessionJwt::issue($customerUser);

        return [
            'user' => $customerUser->fresh(),
            'token' => $plainToken,
        ];
    }

    public function forgotPassword(string $email): void
    {
        $customerUser = $this->findCustomerByEmail($email);

        if (! $customerUser || $customerUser->status !== User::STATUS_ACTIVE) {
            return;
        }

        $ttl = (int) config('customer.password_reset_ttl_minutes');
        [$jwt] = $this->issueActionToken(
            $customerUser,
            UserVerification::TYPE_PASSWORD_RESET,
            $ttl,
            withCode: false,
        );

        try {
            Mail::to($customerUser->email)->send(new CustomerPasswordResetMail($customerUser, $jwt));
        } catch (\Throwable $e) {
            Log::error('Customer password reset email failed', [
                'user_id' => $customerUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resetPassword(string $token, string $password): User
    {
        $verification = $this->findOpenVerification($token, UserVerification::TYPE_PASSWORD_RESET);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired password reset link.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This password reset link has expired. Please request a new one.');
        }

        $customerUser = $verification->user;

        if ($customerUser->status !== User::STATUS_ACTIVE || $customerUser->isLoginBlocked()) {
            throw new \InvalidArgumentException('Invalid or expired password reset link.');
        }

        $this->assertPasswordPolicy($password);

        $customerUser->password = $password;
        $customerUser->save();

        $verification->verified_at = now();
        $verification->consumed_at = now();
        $verification->save();

        SessionJwt::revokeAllFor($customerUser);

        return $customerUser->fresh();
    }

    /**
     * @return string Fresh access JWT (other sessions invalidated via jwt_version bump).
     */
    public function changePassword(User $customerUser, string $current, string $new): string
    {
        if (! $customerUser->password || ! Hash::check($current, $customerUser->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $this->assertPasswordPolicy($new);

        $customerUser->password = $new;
        $customerUser->save();

        SessionJwt::revokeAllFor($customerUser);

        return SessionJwt::issue($customerUser->fresh());
    }

    /**
     * @return array{0: string, 1: string|null} [jwt, code]
     */
    private function issueActionToken(User $user, string $type, int $ttlMinutes, bool $withCode): array
    {
        // Invalidate prior open rows of the same type so only the latest link works.
        UserVerification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $jti = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);
        $code = $withCode ? (string) random_int(100000, 999999) : null;

        UserVerification::create([
            'user_id' => $user->id,
            'type' => $type,
            'jti' => $jti,
            'code_hash' => $code ? Hash::make($code) : null,
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'consumed_at' => null,
            'meta' => null,
        ]);

        $jwt = ActionJwt::encode([
            'sub' => (string) $user->id,
            'typ' => $type,
            'jti' => $jti,
            'exp' => $expiresAt->getTimestamp(),
            'iat' => time(),
        ]);

        return [$jwt, $code];
    }

    private function findOpenVerification(string $jwt, string $type): ?UserVerification
    {
        $claims = ActionJwt::decode($jwt);
        if (! $claims || ($claims['typ'] ?? null) !== $type || empty($claims['jti'])) {
            return null;
        }

        $verification = UserVerification::query()
            ->with('user')
            ->where('jti', $claims['jti'])
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->first();

        if (! $verification || ! $verification->user || ! $verification->user->hasRole(User::ROLE_CUSTOMER)) {
            return null;
        }

        if ((string) $verification->user_id !== (string) ($claims['sub'] ?? '')) {
            return null;
        }

        return $verification;
    }

    private function findCustomerByEmail(string $email): ?User
    {
        return User::whereJsonContains('roles', User::ROLE_CUSTOMER)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }

    private function assertPasswordPolicy(string $password): void
    {
        $rule = Password::min(8);
        $validator = validator(
            ['password' => $password],
            ['password' => [$rule]]
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }
}
