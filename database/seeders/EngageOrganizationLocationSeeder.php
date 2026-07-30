<?php

namespace Database\Seeders;

use App\Models\EngageOrganizationLocation;
use App\Models\EngageToken;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates (or updates) the default Engage organization location from
 * config/engage_organization.php (.env). Also links existing engage_tokens +
 * attaches staff/customer users to users_locations when they have no link yet.
 *
 * Run manually after filling .env:
 *   php artisan db:seed --class=EngageOrganizationLocationSeeder
 *
 * Note: migrate already backfills existing rows onto the default location;
 * this seeder is for fresh setups / env-driven location upserts.
 */
class EngageOrganizationLocationSeeder extends Seeder
{
    public function run(): void
    {
        $timezone = (string) config('engage_organization.timezone');
        $allowed = config('engage_organization.timezones', []);
        if ($timezone !== '' && is_array($allowed) && $allowed !== [] && ! in_array($timezone, $allowed, true)) {
            $this->command?->error("Invalid ENGAGE_ORG_TIMEZONE [{$timezone}]. Use an IANA zone from DateTimeZone::listIdentifiers().");

            return;
        }

        $engageLocationId = config('engage_organization.engage_location_id');
        if (! is_string($engageLocationId) || trim($engageLocationId) === '') {
            $this->command?->error('ENGAGE_ORG_LOCATION_ID (or ENGAGE_LOCATION_ID) is required — engage_location_id is unique and not nullable.');

            return;
        }

        $attrs = [
            'name' => (string) config('engage_organization.name'),
            'legal_business_name' => config('engage_organization.legal_business_name') ?: null,
            'business_email' => config('engage_organization.business_email') ?: null,
            'business_phone' => config('engage_organization.business_phone') ?: null,
            'business_country_code' => config('engage_organization.business_country_code') ?: null,
            'business_website' => config('engage_organization.business_website') ?: null,
            'business_niche' => config('engage_organization.business_niche') ?: null,
            'street_address' => config('engage_organization.street_address') ?: null,
            'city' => config('engage_organization.city') ?: null,
            'postal_code' => config('engage_organization.postal_code') ?: null,
            'state' => config('engage_organization.state') ?: null,
            'country' => config('engage_organization.country') ?: null,
            'timezone' => $timezone ?: null,
            'business_information' => config('engage_organization.business_information') ?: null,
            'engage_location_id' => trim($engageLocationId),
        ];

        $existing = EngageOrganizationLocation::query()
            ->where('engage_location_id', $attrs['engage_location_id'])
            ->first();
        $existing ??= EngageOrganizationLocation::query()->where('is_default', true)->first();
        $existing ??= EngageOrganizationLocation::query()->first();

        if ($existing) {
            $existing->fill($attrs)->save();
            $location = $existing;
            $this->command?->info("Updated engage_organization_locations [{$location->id}] {$location->name}");
        } else {
            $location = EngageOrganizationLocation::create($attrs);
            $this->command?->info("Created engage_organization_locations [{$location->id}] {$location->name}");
        }

        if (config('engage_organization.is_default')) {
            EngageOrganizationLocation::markAsDefault($location);
        }

        // Link tokens that aren't attached yet (prefer matching Engage location_id string).
        $tokenQuery = EngageToken::query()->whereNull('engage_organization_location_id');
        $matched = (clone $tokenQuery)
            ->where('location_id', $location->engage_location_id)
            ->update([
                'engage_organization_location_id' => $location->id,
                'token_type' => EngageToken::TYPE_LOCATION,
            ]);
        $this->command?->info("Linked {$matched} engage_tokens by engage location_id.");

        $remaining = EngageToken::query()
            ->whereNull('engage_organization_location_id')
            ->update([
                'engage_organization_location_id' => $location->id,
                'token_type' => EngageToken::TYPE_LOCATION,
            ]);
        if ($remaining > 0) {
            $this->command?->info("Linked {$remaining} remaining engage_tokens to the default location.");
        }

        // Attach users that have no users_locations row yet (skip pure superadmins).
        $usersLinked = 0;
        User::query()
            ->whereDoesntHave('locationLinks')
            ->whereJsonDoesntContain('roles', User::ROLE_SUPERADMIN)
            ->orderBy('id')
            ->each(function (User $user) use ($location, &$usersLinked) {
                $user->attachLocation($location->id);
                $usersLinked++;
            });
        $this->command?->info("Linked {$usersLinked} users to the default location via users_locations.");
    }
}
