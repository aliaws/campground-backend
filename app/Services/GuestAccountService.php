<?php

namespace App\Services;

use App\Mail\GuestPasswordResetMail;
use App\Mail\GuestVerificationMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class GuestAccountService
{
    /**
     * Hook for public booking: create a guest User linked to the Customer, or no-op.
     *
     * Outcomes:
     * 1. No matching User email → create role=guest User and send verification
     * 2. Existing role=guest → sync customer_id, do not resend email
     * 3. Existing staff/admin/cashier email → conservative no-op (never touch staff login)
     */
    public function ensureGuestAccount(Customer $customer, array $contactData = []): void
    {
        $email = strtolower(trim((string) ($contactData['email'] ?? $customer->email ?? '')));

        if ($email === '') {
            return;
        }

        DB::transaction(function () use ($customer, $contactData, $email) {
            $existing = User::whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $guest = new User;
                $guest->name = $contactData['name'] ?? $customer->name;
                $guest->email = $contactData['email'] ?? $customer->email;
                $guest->role = 'guest';
                $guest->tenant_id = $customer->tenant_id;
                $guest->customer_id = $customer->id;
                $guest->password = null;
                $guest->save();

                $this->initiateVerification($guest);

                return;
            }

            if ($existing->role === 'guest') {
                if ($existing->customer_id !== $customer->id) {
                    $existing->customer_id = $customer->id;
                    $existing->save();
                }

                return;
            }

            // Staff/admin/cashier collision: never create or alter that login.
        });
    }

    public function initiateVerification(User $guestUser): void
    {
        if (! $guestUser->email) {
            throw new \InvalidArgumentException('An email address is required to create a guest account.');
        }

        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(32));

        $guestUser->guest_status = 'pending_verification';
        $guestUser->guest_registered_at = $guestUser->guest_registered_at ?? now();
        $guestUser->guest_action_type = 'email_verification';
        $guestUser->guest_action_token_hash = hash('sha256', $token);
        $guestUser->guest_action_expires_at = now()->addMinutes((int) config('guest.verification_ttl_minutes'));
        $guestUser->guest_verification_code_hash = Hash::make($code);
        $guestUser->guest_verification_attempts = 0;
        $guestUser->save();

        try {
            Mail::to($guestUser->email)->send(new GuestVerificationMail($guestUser, $code, $token));
        } catch (\Throwable $e) {
            Log::error('Guest verification email failed', [
                'user_id' => $guestUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resendVerification(string $email): void
    {
        $guestUser = $this->findGuestByEmail($email);

        if (! $guestUser || $guestUser->guest_status === 'active') {
            if ($guestUser?->guest_status === 'active') {
                throw new \InvalidArgumentException('This account is already verified. Please log in.');
            }

            return;
        }

        $this->initiateVerification($guestUser);
    }

    public function verifyCode(string $token, string $code): User
    {
        $guestUser = $this->findByActionToken($token, 'email_verification');

        if (! $guestUser) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($guestUser->guest_action_expires_at && $guestUser->guest_action_expires_at->isPast()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $maxAttempts = (int) config('guest.max_verification_attempts');
        if ($guestUser->guest_verification_attempts >= $maxAttempts) {
            throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
        }

        if (! $guestUser->guest_verification_code_hash || ! Hash::check($code, $guestUser->guest_verification_code_hash)) {
            $guestUser->guest_verification_attempts = ($guestUser->guest_verification_attempts ?? 0) + 1;
            $guestUser->save();

            if ($guestUser->guest_verification_attempts >= $maxAttempts) {
                throw new \InvalidArgumentException('Too many failed attempts. Please request a new verification code.');
            }

            throw new \InvalidArgumentException('Incorrect verification code.');
        }

        $guestUser->guest_status = 'verified';
        $guestUser->guest_verified_at = now();
        $guestUser->guest_verification_code_hash = null;
        $guestUser->guest_verification_attempts = 0;
        $guestUser->save();

        return $guestUser->fresh();
    }

    /**
     * @return array{user: User, token: string}
     */
    public function createPassword(string $token, string $password): array
    {
        $guestUser = $this->findByActionToken($token, 'email_verification');

        if (! $guestUser) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($guestUser->guest_status !== 'verified') {
            throw new \InvalidArgumentException('Please verify your email before creating a password.');
        }

        if ($guestUser->guest_action_expires_at && $guestUser->guest_action_expires_at->isPast()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $this->assertPasswordPolicy($password);

        // Plain assignment — User's `hashed` cast hashes once.
        $guestUser->password = $password;
        $guestUser->guest_status = 'active';
        $guestUser->guest_action_token_hash = null;
        $guestUser->guest_action_type = null;
        $guestUser->guest_action_expires_at = null;
        $guestUser->guest_verification_code_hash = null;
        $guestUser->save();

        $plainToken = $guestUser->createToken('guest-token')->plainTextToken;

        return [
            'user' => $guestUser->fresh(),
            'token' => $plainToken,
        ];
    }

    public function forgotPassword(string $email): void
    {
        $guestUser = $this->findGuestByEmail($email);

        if (! $guestUser || $guestUser->guest_status !== 'active') {
            return;
        }

        $token = bin2hex(random_bytes(32));

        $guestUser->guest_action_type = 'password_reset';
        $guestUser->guest_action_token_hash = hash('sha256', $token);
        $guestUser->guest_action_expires_at = now()->addMinutes((int) config('guest.password_reset_ttl_minutes'));
        $guestUser->guest_verification_code_hash = null;
        $guestUser->save();

        try {
            Mail::to($guestUser->email)->send(new GuestPasswordResetMail($guestUser, $token));
        } catch (\Throwable $e) {
            Log::error('Guest password reset email failed', [
                'user_id' => $guestUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resetPassword(string $token, string $password): User
    {
        $guestUser = $this->findByActionToken($token, 'password_reset');

        if (! $guestUser) {
            throw new \InvalidArgumentException('Invalid or expired password reset link.');
        }

        if ($guestUser->guest_action_expires_at && $guestUser->guest_action_expires_at->isPast()) {
            throw new \InvalidArgumentException('This password reset link has expired. Please request a new one.');
        }

        $this->assertPasswordPolicy($password);

        $guestUser->password = $password;
        $guestUser->guest_action_token_hash = null;
        $guestUser->guest_action_type = null;
        $guestUser->guest_action_expires_at = null;
        $guestUser->save();

        $guestUser->tokens()->delete();

        return $guestUser->fresh();
    }

    public function changePassword(User $guestUser, string $current, string $new): void
    {
        if (! $guestUser->password || ! Hash::check($current, $guestUser->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $this->assertPasswordPolicy($new);

        $guestUser->password = $new;
        $guestUser->save();

        $currentTokenId = $guestUser->currentAccessToken()?->id;
        $guestUser->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();
    }

    private function findGuestByEmail(string $email): ?User
    {
        return User::where('role', 'guest')
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }

    private function findByActionToken(string $token, string $type): ?User
    {
        return User::where('role', 'guest')
            ->where('guest_action_token_hash', hash('sha256', $token))
            ->where('guest_action_type', $type)
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
