<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('CUSTOMER_FRONTEND_URL'),
    ]),

    'allowed_origins_patterns' => [
        '#^https://[\w-]+\.hostingersite\.com$#',
        '#^https://[\w-]+\.builder-preview\.com$#',
        '#^https://[\w-]+\.accuratedigital\.dev$#',
        '#^https?://(www\.)?wpcamp\.site$#',
    ],

    'allowed_headers' => ['*'],

    // Accept-Encoding: lets the browser app read the request-compression
    // handshake (docs/http-compression.md). Content-Encoding on requests is
    // covered by allowed_headers '*'.
    // X-Compression-* / X-Build-Ms: read by the superadmin compression test page.
    'exposed_headers' => [
        'Accept-Encoding',
        'X-Compression-Encoding',
        'X-Compression-Original-Bytes',
        'X-Compression-Sent-Bytes',
        'X-Compression-Ms',
        'X-Compression-Level',
        'X-Compression-Note',
        'X-Build-Ms',
        'Content-Disposition',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
