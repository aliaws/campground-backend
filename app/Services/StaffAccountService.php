<?php

namespace App\Services;

use App\Mail\StaffPasswordResetMail;
use App\Models\EngageUserVerification;
use App\Models\User;
use App\Support\ActionJwt;
use App\Support\SessionJwt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgot/reset/change password + avatar for staff logins (owner, admin,
 * staff, superadmin — the whole User::STAFF_ROLES set, deliberately
 * including superadmin, which is otherwise org-less/exempt from most of
 * this app's per-org logic). Mirrors CustomerAccountService's
 * forgotPassword/resetPassword/changePassword shape (same
 * EngageUserVerification + ActionJwt infrastructure, same
 * TYPE_PASSWORD_RESET type) but kept as a fully separate service so the
 * existing, working customer flow has zero blast radius — same reasoning
 * OrganizationRegistrationService documented for itself.
 */
class StaffAccountService
{
    public function forgotPassword(string $email): void
    {
        $user = $this->findStaffByEmail($email);

        if (! $user || $user->isLoginBlocked()) {
            return;
        }

        $ttl = (int) config('staff.password_reset_ttl_minutes');
        $token = $this->issueActionToken($user, $ttl);

        try {
            Mail::to($user->email)->send(new StaffPasswordResetMail($user, $token, $user->primaryOrganizationName()));
        } catch (\Throwable $e) {
            Log::error('Staff password reset email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resetPassword(string $token, string $password): User
    {
        $verification = $this->findOpenVerification($token);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired password reset link.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This password reset link has expired. Please request a new one.');
        }

        $user = $verification->user;

        if ($user->isLoginBlocked()) {
            throw new \InvalidArgumentException('This account cannot be reset. Please contact support.');
        }

        $this->assertPasswordPolicy($password);

        // Plain assignment — User's `hashed` cast hashes once.
        $user->password = $password;
        $user->save();

        $verification->verified_at = now();
        $verification->consumed_at = now();
        $verification->save();

        SessionJwt::revokeAllFor($user);

        return $user->fresh();
    }

    /**
     * @return string Fresh access JWT (every other session invalidated).
     */
    public function changePassword(User $user, string $current, string $new): string
    {
        if (! $user->password || ! Hash::check($current, $user->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $this->assertPasswordPolicy($new);

        $user->password = $new;
        $user->save();

        SessionJwt::revokeAllFor($user);

        $locationId = $user->activeOrPrimaryLocationId();
        $user->setActiveLocationId($locationId);

        return SessionJwt::issue($user->fresh(), $locationId);
    }

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        if ($user->avatar_url) {
            $this->deleteAvatarFile($user->avatar_url);
        }

        $path = $file->store('avatars', 'public');
        $user->avatar_url = Storage::url($path);
        $user->save();

        return $user->fresh();
    }

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar_url) {
            $this->deleteAvatarFile($user->avatar_url);
        }

        $user->avatar_url = null;
        $user->save();

        return $user->fresh();
    }

    private function deleteAvatarFile(string $avatarUrl): void
    {
        // avatar_url is a Storage::url() output (e.g. /storage/avatars/x.png)
        // — strip the public disk's URL prefix to get back the storage path.
        $path = ltrim(str_replace('/storage/', '', parse_url($avatarUrl, PHP_URL_PATH) ?? ''), '/');
        if ($path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    private function issueActionToken(User $user, int $ttlMinutes): string
    {
        // Invalidate prior open password-reset rows so only the latest link works.
        EngageUserVerification::query()
            ->where('user_id', $user->id)
            ->where('type', EngageUserVerification::TYPE_PASSWORD_RESET)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $jti = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);

        EngageUserVerification::create([
            'user_id' => $user->id,
            'type' => EngageUserVerification::TYPE_PASSWORD_RESET,
            'jti' => $jti,
            'code_hash' => null,
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'consumed_at' => null,
            'meta' => null,
        ]);

        return ActionJwt::encode([
            'sub' => (string) $user->id,
            'typ' => EngageUserVerification::TYPE_PASSWORD_RESET,
            'jti' => $jti,
            'exp' => $expiresAt->getTimestamp(),
            'iat' => time(),
        ]);
    }

    private function findOpenVerification(string $jwt): ?EngageUserVerification
    {
        $claims = ActionJwt::decode($jwt);
        if (! $claims || ($claims['typ'] ?? null) !== EngageUserVerification::TYPE_PASSWORD_RESET || empty($claims['jti'])) {
            return null;
        }

        $verification = EngageUserVerification::query()
            ->with('user')
            ->where('jti', $claims['jti'])
            ->where('type', EngageUserVerification::TYPE_PASSWORD_RESET)
            ->whereNull('consumed_at')
            ->first();

        if (! $verification || ! $verification->user || ! $verification->user->hasAnyRole(...User::STAFF_ROLES)) {
            return null;
        }

        if ((string) $verification->user_id !== (string) ($claims['sub'] ?? '')) {
            return null;
        }

        return $verification;
    }

    private function findStaffByEmail(string $email): ?User
    {
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        return $user && $user->hasAnyRole(...User::STAFF_ROLES) ? $user : null;
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
