<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Country;
use App\Models\EngageOrganizationLocation;
use App\Models\OrganizationSetting;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Settings page "Sync from HighLevel": reads the organization's own location
 * from Lead Connector (GET locations/{id}) and copies chosen values into the
 * business profile (name, email, phone, address, timezone) and the Settings
 * logo. preview() never saves; apply() re-reads Lead Connector itself, so
 * nothing the browser sends is trusted as a value.
 */
class LeadConnectorLocationSyncService
{
    public const FIELDS = ['name', 'email', 'phone', 'address', 'timezone', 'logo'];

    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private GhlClient $client,
        private GhlLocationContext $context,
    ) {}

    /**
     * Lead Connector's values next to the current ones, per field.
     *
     * @return array<string, array{label: string, current: ?string, lead_connector: ?string, changed: bool}>
     */
    public function preview(EngageOrganizationLocation $organization): array
    {
        $remote = $this->fetch($organization);
        $settings = OrganizationSetting::forOrganization($organization->id);

        $current = [
            'name' => $organization->name,
            'email' => $organization->business_email,
            'phone' => $organization->business_phone,
            'address' => $this->addressLine($organization->street_address, $organization->city, $organization->state, $organization->postal_code, $organization->country),
            'timezone' => $organization->timezone,
            'logo' => $settings->logo_light_url,
        ];
        $incoming = [
            'name' => $remote['name'],
            'email' => $remote['email'],
            'phone' => $remote['phone'],
            'address' => $this->addressLine($remote['street_address'], $remote['city'], $remote['state'], $remote['postal_code'], $remote['country']),
            'timezone' => $remote['timezone'],
            'logo' => $remote['logo_url'],
        ];
        $labels = ['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'timezone' => 'Timezone', 'logo' => 'Logo'];

        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = [
                'label' => $labels[$field],
                'current' => $current[$field] ?: null,
                'lead_connector' => $incoming[$field] ?: null,
                // The logo is always a new copy, so only "has one" matters there.
                'changed' => $incoming[$field] !== null && $incoming[$field] !== ''
                    && ($field === 'logo' || trim((string) $current[$field]) !== trim((string) $incoming[$field])),
            ];
        }

        return $out;
    }

    /**
     * Copies the chosen fields. Fields Lead Connector has no value for are
     * skipped, never blanked.
     *
     * @param  string[]  $fields
     * @return string[] the fields that were actually updated
     */
    public function apply(EngageOrganizationLocation $organization, array $fields): array
    {
        $remote = $this->fetch($organization);
        $applied = [];
        $profile = [];

        if (in_array('name', $fields, true) && $remote['name']) {
            $profile['name'] = Str::limit($remote['name'], 255, '');
            $applied[] = 'name';
        }
        if (in_array('email', $fields, true) && $remote['email'] && filter_var($remote['email'], FILTER_VALIDATE_EMAIL)) {
            $profile['business_email'] = $remote['email'];
            $applied[] = 'email';
        }
        if (in_array('phone', $fields, true) && $remote['phone']) {
            $profile['business_phone'] = Str::limit($remote['phone'], 30, '');
            $applied[] = 'phone';
        }
        if (in_array('address', $fields, true) && $this->addressLine($remote['street_address'], $remote['city'], $remote['state'], $remote['postal_code'], $remote['country'])) {
            $profile['street_address'] = $remote['street_address'] ? Str::limit($remote['street_address'], 255, '') : null;
            $profile['city'] = $remote['city'] ? Str::limit($remote['city'], 255, '') : null;
            $profile['state'] = $remote['state'] ? Str::limit($remote['state'], 100, '') : null;
            $profile['postal_code'] = $remote['postal_code'] ? Str::limit($remote['postal_code'], 20, '') : null;
            // Lead Connector gives a 2-letter code; the profile stores the country name + code.
            if ($remote['country_code'] && ($country = Country::query()->where('iso2', $remote['country_code'])->first())) {
                $profile['country'] = $country->name;
                $profile['business_country_code'] = $country->iso2;
            }
            $applied[] = 'address';
        }
        if (in_array('timezone', $fields, true) && $remote['timezone'] && in_array($remote['timezone'], timezone_identifiers_list(), true)) {
            $profile['timezone'] = $remote['timezone'];
            $applied[] = 'timezone';
        }

        if ($profile !== []) {
            $organization->update($profile);
        }

        if (in_array('logo', $fields, true) && $remote['logo_url'] && $this->importLogo($organization, $remote['logo_url'])) {
            $applied[] = 'logo';
        }

        return $applied;
    }

    /**
     * @return array{name: ?string, email: ?string, phone: ?string, street_address: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string, country_code: ?string, timezone: ?string, logo_url: ?string}
     */
    private function fetch(EngageOrganizationLocation $organization): array
    {
        $locationId = $organization->engage_location_id;
        if (! $locationId) {
            throw new \RuntimeException('This organization has no Lead Connector location linked.');
        }

        $this->context->set($organization->id);
        try {
            $response = $this->client->get("locations/{$locationId}", [], '2021-07-28');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '401') && str_contains(strtolower($e->getMessage()), 'scope')) {
                throw new \RuntimeException(
                    'Lead Connector refused access to the location details. Add the "locations.readonly" scope in Lead Connector Identifiers and in the Lead Connector app, then reconnect.'
                );
            }
            throw new \RuntimeException('Could not read the location from Lead Connector: '.$e->getMessage());
        } finally {
            $this->context->set(null);
        }

        $location = $response['location'] ?? $response;
        $business = is_array($location['business'] ?? null) ? $location['business'] : [];
        // The business block is what the location's Business Profile page edits; fall back to the top-level fields.
        $pick = fn (string $key) => $this->clean($business[$key] ?? null) ?? $this->clean($location[$key] ?? null);
        $countryCode = $pick('country');

        return [
            'name' => $pick('name'),
            'email' => $pick('email'),
            'phone' => $this->clean($location['phone'] ?? null) ?? $this->clean($business['phone'] ?? null),
            'street_address' => $pick('address'),
            'city' => $pick('city'),
            'state' => $pick('state'),
            'postal_code' => $pick('postalCode'),
            'country' => $countryCode,
            'country_code' => $countryCode && strlen($countryCode) === 2 ? strtoupper($countryCode) : null,
            'timezone' => $pick('timezone'),
            'logo_url' => $pick('logoUrl'),
        ];
    }

    private function importLogo(EngageOrganizationLocation $organization, string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Lead Connector logo download failed.', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/gif' => 'gif'];
        $body = $response->body();
        if (! $response->successful() || ! isset($extensions[$type]) || $body === '' || strlen($body) > self::LOGO_MAX_BYTES) {
            Log::warning('Lead Connector logo was not a usable image.', ['url' => $url, 'status' => $response->status(), 'type' => $type]);

            return false;
        }

        $path = 'organization-branding/'.Str::random(40).'.'.$extensions[$type];
        Storage::disk('public')->put($path, $body);

        $settings = OrganizationSetting::forOrganization($organization->id);
        $old = $settings->logo_light_url;
        $settings->logo_light_url = PublicStorageUrl::absolute(Storage::url($path));
        $settings->save();

        if ($old && PublicStorageUrl::isOwnStoragePath($old)) {
            Storage::disk('public')->delete(PublicStorageUrl::diskRelativePath($old));
        }

        return true;
    }

    private function addressLine(?string ...$parts): ?string
    {
        $line = implode(', ', array_filter(array_map(fn ($p) => $p !== null ? trim($p) : '', $parts)));

        return $line !== '' ? $line : null;
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
