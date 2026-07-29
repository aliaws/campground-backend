<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engage / GoHighLevel Settings (seeded into engage_settings)
    |--------------------------------------------------------------------------
    */

    'tenant_id' => env('ENGAGE_TENANT_ID'),

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

];
