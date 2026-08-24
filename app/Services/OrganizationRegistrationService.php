<?php

namespace App\Services;

use App\Mail\OrganizationRegistrationMail;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageUserVerification;
use App\Models\User;
use App\Support\ActionJwt;
use App\Support\SessionJwt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self-service organization onboarding: a visitor submits a GHL location id
 * on the public "Register for Application" page (before OAuth even
 * happens), then — once GhlOAuth callback saves tokens for that location —
 * fills in business info, verifies the business email via a 6-digit code,
 * and sets a password for a new (or linked-if-existing) owner account.
 * Deliberately mirrors CustomerAccountService's register/verifyCode/
 * createPassword shape (same EngageUserVerification + ActionJwt
 * infrastructure) rather than sharing code with it — that service is
 * hardcoded to ROLE_CUSTOMER throughout, and duplicating this focused
 * service keeps zero risk of regressing the existing customer flow.
 */
class OrganizationRegistrationService
{
    /**
     * Called the moment a visitor submits a location id, before any GHL
     * OAuth redirect happens. `engage_location_id` is already DB-unique
     * (see engage_organization_locations migration) — that's the real
     * duplicate guard; the QueryException catch below just turns a raw SQL
     * constraint violation into a friendly message.
     */
    public function registerLocation(string $locationId): EngageOrganizationLocation
    {
        if (EngageOrganizationLocation::query()->where('engage_location_id', $locationId)->exists()) {
            throw new \InvalidArgumentException('This location has already been registered.');
        }

        try {
            // `status` is deliberately not mass-assignable (see the model's
            // own fillable comment — only block()/unblock() and this
            // direct-assignment path may set it), so it's set explicitly
            // after create() rather than passed into the array above.
            $organization = EngageOrganizationLocation::create([
                'engage_location_id' => $locationId,
                'name' => null,
            ]);
            $organization->status = EngageOrganizationLocation::STATUS_UNINSTALLED;
            $organization->save();

            return $organization;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'engage_location_id')) {
                throw new \InvalidArgumentException('This location has already been registered.');
            }

            throw $e;
        }
    }

    /**
     * Called from SettingsController::handleCallback()'s state-less branch
     * once GHL tokens have been exchanged for this location. Finds the row
     * registerLocation() created; defensively creates one if somehow
     * missing (e.g. callback reached without going through the public
     * register step first) rather than hard-failing.
     */
    public function findOrCreateByGhlLocationId(string $ghlLocationId): EngageOrganizationLocation
    {
        $organization = EngageOrganizationLocation::query()->where('engage_location_id', $ghlLocationId)->first();

        if ($organization) {
            return $organization;
        }

        // `status` is deliberately not mass-assignable — see registerLocation()'s comment.
        $organization = EngageOrganizationLocation::create(['engage_location_id' => $ghlLocationId, 'name' => null]);
        $organization->status = EngageOrganizationLocation::STATUS_UNINSTALLED;
        $organization->save();

        return $organization;
    }

    /**
     * Complete Registration form submit: saves business info onto the
     * organization, finds-or-creates the owner User (never touches an
     * existing staff/admin/superadmin account — same conservative
     * collision handling as CustomerAccountService::ensureCustomerAccount()),
     * and emails a verification code to business_email. The organization
     * stays 'uninstalled' until the code is confirmed.
     */
    public function completeRegistration(EngageOrganizationLocation $organization, array $data): void
    {
        $email = strtolower(trim((string) ($data['business_email'] ?? '')));

        if ($email === '') {
            throw new \InvalidArgumentException('A business email address is required.');
        }

        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing && ! $existing->hasRole(User::ROLE_OWNER) && ! $existing->hasRole(User::ROLE_ADMIN) && ! $existing->hasRole(User::ROLE_STAFF)) {
            throw new \InvalidArgumentException('This email address cannot be used to register an organization.');
        }

        DB::transaction(function () use ($organization, $data, $email, $existing) {
            $organization->fill([
                'name' => $data['name'] ?? null,
                'legal_business_name' => $data['legal_business_name'] ?? null,
                'business_email' => $email,
                'business_phone' => $data['business_phone'] ?? null,
                'business_country_code' => $data['business_country_code'] ?? null,
                'business_website' => $data['business_website'] ?? null,
                'state' => $data['state'] ?? null,
                'city' => $data['city'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? null,
                'business_information' => filled($data['business_information'] ?? null)
                    ? ['text' => $data['business_information']]
                    : null,
            ]);
            $organization->save();

            $ownerUser = $existing;

            if (! $ownerUser) {
                $ownerUser = new User;
                $ownerUser->name = $data['name'] ?? $email;
                $ownerUser->email = $email;
                $ownerUser->roles = [User::ROLE_OWNER];
                $ownerUser->status = User::STATUS_PENDING;
                $ownerUser->password = null;
                $ownerUser->save();
            } elseif (! $ownerUser->hasRole(User::ROLE_OWNER)) {
                // Existing admin/staff account registering a new org of
                // their own — grant owner access (roles are global to the
                // user, not per-org, matching this schema's existing design).
                $ownerUser->roles = [...$ownerUser->roleList(), User::ROLE_OWNER];
                $ownerUser->save();
            }

            $ownerUser->attachLocation($organization->id);

            $this->initiateVerification($organization, $ownerUser);
        });
    }

    public function resendVerification(EngageOrganizationLocation $organization): void
    {
        if (! $organization->business_email) {
            throw new \InvalidArgumentException('Complete registration before requesting another code.');
        }

        if (! $organization->isUninstalled()) {
            throw new \InvalidArgumentException('This organization is already active.');
        }

        $ownerUser = User::whereRaw('LOWER(email) = ?', [strtolower($organization->business_email)])->first();

        if (! $ownerUser) {
            throw new \InvalidArgumentException('No pending registration found for this organization.');
        }

        $this->initiateVerification($organization, $ownerUser);
    }

    /**
     * @return array{organization: EngageOrganizationLocation, user: User, needs_password: bool}
     */
    public function verifyCode(string $token, string $code): array
    {
        $verification = $this->findOpenVerification($token, EngageUserVerification::TYPE_EMAIL_VERIFICATION);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $maxAttempts = (int) config('organization.max_verification_attempts');
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

        $organizationId = $verification->meta['organization_id'] ?? null;
        $organization = $organizationId ? EngageOrganizationLocation::find($organizationId) : null;

        if (! $organization) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        $verification->verified_at = now();
        $verification->code_hash = null;
        $verification->attempts = 0;
        $verification->save();

        $ownerUser = $verification->user->fresh();

        // Activating the organization is what this code was actually for —
        // an already-active owner (existing account linked to a second
        // organization) needs no password step, only the org flip.
        if ($organization->isUninstalled()) {
            $organization->status = EngageOrganizationLocation::STATUS_ACTIVE;
            $organization->save();
        }

        return [
            'organization' => $organization->fresh(),
            'user' => $ownerUser,
            'needs_password' => $ownerUser->status !== User::STATUS_ACTIVE,
        ];
    }

    /**
     * @return array{organization: EngageOrganizationLocation, user: User, token: string}
     */
    public function createPassword(string $token, string $password, ?string $name = null): array
    {
        $verification = $this->findOpenVerification($token, EngageUserVerification::TYPE_EMAIL_VERIFICATION);

        if (! $verification) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        if (! $verification->isVerified()) {
            throw new \InvalidArgumentException('Please verify your email before creating a password.');
        }

        if ($verification->isExpired()) {
            throw new \InvalidArgumentException('This verification link has expired. Please request a new one.');
        }

        $organizationId = $verification->meta['organization_id'] ?? null;
        $organization = $organizationId ? EngageOrganizationLocation::find($organizationId) : null;

        if (! $organization) {
            throw new \InvalidArgumentException('Invalid or expired verification link.');
        }

        $ownerUser = $verification->user;

        if ($ownerUser->isLoginBlocked()) {
            throw new \InvalidArgumentException('This account cannot be activated. Please contact support.');
        }

        $this->assertPasswordPolicy($password);

        if (filled($name)) {
            $ownerUser->name = $name;
        }
        // Plain assignment — User's `hashed` cast hashes once.
        $ownerUser->password = $password;
        $ownerUser->status = User::STATUS_ACTIVE;
        $ownerUser->save();

        $verification->consumed_at = now();
        $verification->save();

        $ids = collect($ownerUser->activeLocationIds());
        $locationId = $ids->count() === 1 ? $ids->first() : null;
        $ownerUser->setActiveLocationId($locationId);
        $plainToken = SessionJwt::issue($ownerUser, $locationId);

        return [
            'organization' => $organization->fresh(),
            'user' => $ownerUser->fresh(),
            'token' => $plainToken,
        ];
    }

    private function initiateVerification(EngageOrganizationLocation $organization, User $ownerUser): void
    {
        $ttl = (int) config('organization.verification_ttl_minutes');
        [$jwt, $code] = $this->issueActionToken($ownerUser, $organization, $ttl);

        try {
            Mail::to($organization->business_email)->send(
                new OrganizationRegistrationMail($organization, $ownerUser, $code, $jwt)
            );
        } catch (\Throwable $e) {
            Log::error('Organization registration verification email failed', [
                'organization_id' => $organization->id,
                'user_id' => $ownerUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [jwt, code]
     */
    private function issueActionToken(User $ownerUser, EngageOrganizationLocation $organization, int $ttlMinutes): array
    {
        // Invalidate prior open rows for this user so only the latest link works.
        EngageUserVerification::query()
            ->where('user_id', $ownerUser->id)
            ->where('type', EngageUserVerification::TYPE_EMAIL_VERIFICATION)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $jti = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);
        $code = (string) random_int(100000, 999999);

        EngageUserVerification::create([
            'user_id' => $ownerUser->id,
            'type' => EngageUserVerification::TYPE_EMAIL_VERIFICATION,
            'jti' => $jti,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'consumed_at' => null,
            'meta' => ['organization_id' => $organization->id],
        ]);

        $jwt = ActionJwt::encode([
            'sub' => (string) $ownerUser->id,
            'typ' => EngageUserVerification::TYPE_EMAIL_VERIFICATION,
            'jti' => $jti,
            'exp' => $expiresAt->getTimestamp(),
            'iat' => time(),
        ]);

        return [$jwt, $code];
    }

    private function findOpenVerification(string $jwt, string $type): ?EngageUserVerification
    {
        $claims = ActionJwt::decode($jwt);
        if (! $claims || ($claims['typ'] ?? null) !== $type || empty($claims['jti'])) {
            return null;
        }

        $verification = EngageUserVerification::query()
            ->with('user')
            ->where('jti', $claims['jti'])
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->first();

        if (! $verification || ! $verification->user || ! isset($verification->meta['organization_id'])) {
            return null;
        }

        if ((string) $verification->user_id !== (string) ($claims['sub'] ?? '')) {
            return null;
        }

        return $verification;
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
