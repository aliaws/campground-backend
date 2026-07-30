<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engage / GoHighLevel Settings (seeded into engage_settings)
    |--------------------------------------------------------------------------
    |
    | `tenant_id` remains as the config key used by EngageSettingSeeder (database/
    | seeders are left unchanged). It seeds the physical engage_settings.tenant_id
    | column, which app code exposes as oauth_state_key.
    */

    'tenant_id' => env('ENGAGE_TENANT_ID'),

    'oauth_state_key' => env('ENGAGE_OAUTH_STATE_KEY', env('ENGAGE_TENANT_ID')),

    'client_id' => env('ENGAGE_CLIENT_ID'),

    'client_secret' => env('ENGAGE_CLIENT_SECRET'),

    'api_version' => env('ENGAGE_API_VERSION', '2021-07-28'),

    'api_base_url' => env('ENGAGE_API_BASE_URL', 'https://services.leadconnectorhq.com/'),

    'timezone' => env('ENGAGE_TIMEZONE', 'America/New_York'),

    // JSON array of OAuth scope strings, e.g. '["contacts.readonly","contacts.write"]'
    'scopes' => json_decode(env('ENGAGE_SCOPES', '[]'), true) ?: [],

    /*
    |--------------------------------------------------------------------------
    | Engage Tokens (optional seed values for engage_tokens)
    |--------------------------------------------------------------------------
    */

    'location_id' => env('ENGAGE_LOCATION_ID'),

    'company_id' => env('ENGAGE_COMPANY_ID'),

    'user_id' => env('ENGAGE_USER_ID'),

    'authorization_code' => env('ENGAGE_AUTHORIZATION_CODE'),

    'access_token' => env('ENGAGE_ACCESS_TOKEN'),

    'refresh_token' => env('ENGAGE_REFRESH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | SaaS Super Admin (platform owner — NOT a location owner)
    | Manual: php artisan db:seed --class=SuperAdminSeeder
    |--------------------------------------------------------------------------
    */

    'superadmin' => [
        'name' => env('SUPERADMIN_NAME', 'Super Admin'),
        'email' => env('SUPERADMIN_EMAIL'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Location Owner (one owner per Engage organization location)
    | Manual: php artisan db:seed --class=EngageLocationOwnerSeeder
    | Requires EngageOrganizationLocationSeeder first.
    |--------------------------------------------------------------------------
    */

    'location_owner' => [
        'name' => env('ENGAGE_ORG_OWNER_NAME', 'Location Owner'),
        'email' => env('ENGAGE_ORG_OWNER_EMAIL'),
        'password' => env('ENGAGE_ORG_OWNER_PASSWORD'),
    ],

];
