<?php

namespace App\Services;

use App\Mail\CustomerPasswordResetMail;
use App\Mail\CustomerRegistrationMail;
use App\Mail\CustomerVerificationMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            if ($existing->role !== 'customer') {
                throw new \InvalidArgumentException('An account already exists for this email.');
            }

            if ($existing->customer_status === 'active') {
                throw new \InvalidArgumentException('An account already exists for this email. Please log in or use Forgot Password.');
            }

            // Pending/verified but never finished registering — let them retry cleanly.
            $this->initiateVerification($existing, CustomerRegistrationMail::class);

            return $existing->fresh();
        }

        $tenantId = TenantResolver::resolveDefault();
        $customer = $this->customerService->findOrCreate($data, $tenantId, User::createdByLabel(null, $data['name'] ?? ''));

        try {
            $this->ghlService->syncContactToGhl($customer);
        } catch (\Exception $e) {
            Log::error('GHL sync failed for customer registration', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $customerUser = new User;
        $customerUser->name = $data['name'] ?? $customer->name;
        $customerUser->email = $data['email'];
        $customerUser->role = 'customer';
        $customerUser->tenant_id = $tenantId;
        $customerUser->customer_id = $customer->id;
        $customerUser->password = null;
        $customerUser->save();

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
     */
    public function ensureCustomerAccount(Customer $customer, array $contactData = [], bool $sendEmail = true): void
    {
        $email = strtolower(trim((string) ($contactData['email'] ?? $customer->email ?? '')));

        if ($email === '') {
            return;
        }

        DB::transaction(function () use ($customer, $contactData, $email, $sendEmail) {
            $existing = User::whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $customerUser = new User;
                $customerUser->name = $contactData['name'] ?? $customer->name;
                $customerUser->email = $contactData['email'] ?? $customer->email;
                $customerUser->role = 'customer';
                $customerUser->tenant_id = $customer->tenant_id;
                $customerUser->customer_id = $customer->id;
                $customerUser->password = null;
                $customerUser->save();

                $this->initiateVerification($customerUser, CustomerVerificationMail::class, $sendEmail);

                return;
            }

            if ($existing->role === 'customer') {
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
    public function deleteCustomerAccount(Customer $customer): void
    {
        $customerUser = $customer->customerAccount;

        if (! $customerUser) {
            return;
        }

        $customerUser->tokens()->delete();
        $customerUser->delete();
    }

    /**
     * @param  class-string  $mailableClass  CustomerVerificationMail (default — booking/contact-created path)
     *                                       or CustomerRegistrationMail (direct self-registration via /customer/register).
     *                                       Both share the same (User, code, token) constructor signature.
     * @param  bool  $sendEmail  Always generates and stores a fresh code/token regardless — false only
     *                           skips actually emailing it (see ensureCustomerAccount()'s $sendEmail doc).
     */
    public function initiateVerification(User $customerUser, string $mailableClass = CustomerVerificationMail::class, bool $sendEmail = true): void
    {
        if (! $customerUser->email) {
            throw new \InvalidArgumentException('An email address is required to create a customer account.');
        }

        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(32));

        $customerUser->customer_status = 'pending_verification';
        $customerUser->customer_registered_at = $customerUser->customer_registered_at ?? now();
        $customerUser->customer_account_type = 'email_verification';
        $customerUser->customer_account_token_hash = hash('sha256', $token);
        $customerUser->customer_account_expires_at = now()->addMinutes((int) config('customer.verification_ttl_minutes'));
        $customerUser->customer_verification_code_hash = Hash::make($code);
        $customerUser->customer_verification_attempts = 0;
        $customerUser->save();

        if (! $sendEmail) {
            return;
        }

        try {
            Mail::to($customerUser->email)->send(new $mailableClass($customerUser, $code, $token));
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

        if (! $customerUser || $customerUser->customer_status === 'active') {
            if ($customerUser?->customer_status === 'active') {
                throw new \InvalidArgumentException('This account is already verified. Please log in.');
            }

            return;
        }

        $this->initiateVerification($customerUser);
    }

    public function verifyCode(string $token, string $code): User
    {
        $customerUser = $this->findByActionToken($token, 'email_verification');

        if (! $customerUser) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($customerUser->customer_account_expires_at && $customerUser->customer_account_expires_at->isPast()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $maxAttempts = (int) config('customer.max_verification_attempts');
        if ($customerUser->customer_verification_attempts >= $maxAttempts) {
            throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
        }

        if (! $customerUser->customer_verification_code_hash || ! Hash::check($code, $customerUser->customer_verification_code_hash)) {
            $customerUser->customer_verification_attempts = ($customerUser->customer_verification_attempts ?? 0) + 1;
            $customerUser->save();

            if ($customerUser->customer_verification_attempts >= $maxAttempts) {
                throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
            }

            throw new \InvalidArgumentException('Incorrect verification code.');
        }

        $customerUser->customer_status = 'verified';
        $customerUser->customer_verified_at = now();
        $customerUser->customer_verification_code_hash = null;
        $customerUser->customer_verification_attempts = 0;
        $customerUser->save();

        return $customerUser->fresh();
    }

    /**
     * @return array{user: User, token: string}
     */
    public function createPassword(string $token, string $password): array
    {
        $customerUser = $this->findByActionToken($token, 'email_verification');

        if (! $customerUser) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($customerUser->customer_status !== 'verified') {
            throw new \InvalidArgumentException('Please verify your email before creating a password.');
        }

        if ($customerUser->customer_account_expires_at && $customerUser->customer_account_expires_at->isPast()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $this->assertPasswordPolicy($password);

        // Plain assignment — User's `hashed` cast hashes once.
        $customerUser->password = $password;
        $customerUser->customer_status = 'active';
        $customerUser->customer_account_token_hash = null;
        $customerUser->customer_account_type = null;
        $customerUser->customer_account_expires_at = null;
        $customerUser->customer_verification_code_hash = null;
        $customerUser->save();

        $plainToken = $customerUser->createToken('customer-token')->plainTextToken;

        return [
            'user' => $customerUser->fresh(),
            'token' => $plainToken,
        ];
    }

    public function forgotPassword(string $email): void
    {
        $customerUser = $this->findCustomerByEmail($email);

        if (! $customerUser || $customerUser->customer_status !== 'active') {
            return;
        }

        $token = bin2hex(random_bytes(32));

        $customerUser->customer_account_type = 'password_reset';
        $customerUser->customer_account_token_hash = hash('sha256', $token);
        $customerUser->customer_account_expires_at = now()->addMinutes((int) config('customer.password_reset_ttl_minutes'));
        $customerUser->customer_verification_code_hash = null;
        $customerUser->save();

        try {
            Mail::to($customerUser->email)->send(new CustomerPasswordResetMail($customerUser, $token));
        } catch (\Throwable $e) {
            Log::error('Customer password reset email failed', [
                'user_id' => $customerUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resetPassword(string $token, string $password): User
    {
        $customerUser = $this->findByActionToken($token, 'password_reset');

        if (! $customerUser) {
            throw new \InvalidArgumentException('Invalid or expired password reset link.');
        }

        if ($customerUser->customer_account_expires_at && $customerUser->customer_account_expires_at->isPast()) {
            throw new \InvalidArgumentException('This password reset link has expired. Please request a new one.');
        }

        $this->assertPasswordPolicy($password);

        $customerUser->password = $password;
        $customerUser->customer_account_token_hash = null;
        $customerUser->customer_account_type = null;
        $customerUser->customer_account_expires_at = null;
        $customerUser->save();

        $customerUser->tokens()->delete();

        return $customerUser->fresh();
    }

    public function changePassword(User $customerUser, string $current, string $new): void
    {
        if (! $customerUser->password || ! Hash::check($current, $customerUser->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $this->assertPasswordPolicy($new);

        $customerUser->password = $new;
        $customerUser->save();

        $currentTokenId = $customerUser->currentAccessToken()?->id;
        $customerUser->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();
    }

    private function findCustomerByEmail(string $email): ?User
    {
        return User::where('role', 'customer')
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }

    private function findByActionToken(string $token, string $type): ?User
    {
        return User::where('role', 'customer')
            ->where('customer_account_token_hash', hash('sha256', $token))
            ->where('customer_account_type', $type)
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
