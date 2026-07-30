<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Engage Organization Location (seeded, not auto-run)
    |--------------------------------------------------------------------------
    |
    | Fill these in .env, then run:
    |   php artisan db:seed --class=EngageOrganizationLocationSeeder
    |
    */

    'name' => env('ENGAGE_ORG_NAME', 'Default Location'),

    'legal_business_name' => env('ENGAGE_ORG_LEGAL_NAME'),

    'business_email' => env('ENGAGE_ORG_BUSINESS_EMAIL'),

    'business_phone' => env('ENGAGE_ORG_BUSINESS_PHONE'),

    'business_country_code' => env('ENGAGE_ORG_BUSINESS_COUNTRY_CODE'),

    'business_website' => env('ENGAGE_ORG_BUSINESS_WEBSITE'),

    'business_niche' => env('ENGAGE_ORG_BUSINESS_NICHE'),

    'street_address' => env('ENGAGE_ORG_STREET_ADDRESS'),

    'city' => env('ENGAGE_ORG_CITY'),

    'postal_code' => env('ENGAGE_ORG_POSTAL_CODE'),

    'state' => env('ENGAGE_ORG_STATE'),

    'country' => env('ENGAGE_ORG_COUNTRY'),

    // Must be a valid IANA timezone (see DateTimeZone::listIdentifiers())
    'timezone' => env('ENGAGE_ORG_TIMEZONE', env('ENGAGE_TIMEZONE', 'America/New_York')),

    // Optional free-form JSON object string, e.g. '{"tax_id":"…"}'
    'business_information' => json_decode(env('ENGAGE_ORG_BUSINESS_INFORMATION', '[]'), true) ?: [],

    'engage_location_id' => env('ENGAGE_ORG_LOCATION_ID', env('ENGAGE_LOCATION_ID')),

    'is_default' => filter_var(env('ENGAGE_ORG_IS_DEFAULT', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | All IANA timezones (for selects / validation)
    |--------------------------------------------------------------------------
    */
    'timezones' => DateTimeZone::listIdentifiers(),

];
