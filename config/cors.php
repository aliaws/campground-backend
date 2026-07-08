<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('CUSTOMER_FRONTEND_URL'),
    ]),

    'allowed_origins_patterns' => [
        '#^https://[\w-]+\.hostingersite\.com$#',
        '#^https://[\w-]+\.builder-preview\.com$#',
        '#^https://[\w-]+\.accuratedigital\.dev$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
