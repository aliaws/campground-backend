<?php

namespace Database\Seeders;

use App\Models\EngageOrganizationLocation;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Location owner for the default Engage organization location.
 * Exactly one owner per location — never mix with SaaS superadmin.
 * Attaches location via users_locations (no column on users).
 *
 * Prerequisites: EngageOrganizationLocationSeeder
 * Fill ENGAGE_ORG_OWNER_* in .env, then:
 *   php artisan db:seed --class=EngageLocationOwnerSeeder
 */
class EngageLocationOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('engage.location_owner.email')));
        $password = (string) config('engage.location_owner.password');
        $name = trim((string) config('engage.location_owner.name')) ?: 'Location Owner';

        if ($email === '' || $password === '') {
            $this->command?->error('ENGAGE_ORG_OWNER_EMAIL and ENGAGE_ORG_OWNER_PASSWORD are required in .env');

            return;
        }

        if (strlen($password) < 8) {
            $this->command?->error('ENGAGE_ORG_OWNER_PASSWORD must be at least 8 characters.');

            return;
        }

        $location = EngageOrganizationLocation::query()->where('is_default', true)->first()
            ?? EngageOrganizationLocation::query()->first();

        if (! $location) {
            $this->command?->error('No engage_organization_locations row found. Run EngageOrganizationLocationSeeder first.');

            return;
        }

        // One owner per location (via junction).
        $existingOwner = User::query()
            ->whereJsonContains('roles', User::ROLE_OWNER)
            ->whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $location->id)
            )
            ->first();

        $byEmail = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existingOwner && (! $byEmail || $existingOwner->id !== $byEmail->id)) {
            $this->command?->error(
                "Location [{$location->id}] already has an owner ({$existingOwner->email}). ".
                'Refusing to create a second owner.'
            );

            return;
        }

        if ($byEmail) {
            if ($byEmail->hasRole(User::ROLE_SUPERADMIN)) {
                $this->command?->error('That email is the SaaS superadmin — do not reuse it as a location owner.');

                return;
            }

            $byEmail->name = $name;
            $byEmail->password = $password;
            $byEmail->roles = [User::ROLE_OWNER];
            $byEmail->status = User::STATUS_ACTIVE;
            $byEmail->save();
            $byEmail->attachLocation($location->id);

            $this->command?->info("Updated location owner [{$byEmail->id}] {$byEmail->email} for {$location->name}");

            return;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'roles' => [User::ROLE_OWNER],
            'status' => User::STATUS_ACTIVE,
            'created_by' => null,
        ]);
        $user->attachLocation($location->id);

        $this->command?->info("Created location owner [{$user->id}] {$user->email} for {$location->name}");
    }
}
