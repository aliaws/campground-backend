<?php

/*
| HTTP compression between the browser app and the API.
| See docs/http-compression.md for how it works and how to deploy it.
*/

$skipPaths = [
    'mcp',
    'mcp/*',
    'api/mcp/*',
    'oauth/*',
    '.well-known/*',
    'api/.well-known/*',
    'api/webhooks/*',
    // This project's API lives under api/v1.
    'api/v1/webhooks/*',
    'api/v1/settings/engage/callback',
];

return [

    'response' => [
        'enabled' => env('RESPONSE_COMPRESSION_ENABLED', true),

        // Bodies smaller than this are sent plain.
        'min_bytes' => (int) env('RESPONSE_COMPRESSION_MIN_BYTES', 1024),

        // Tie-break order when the client gives several encodings the same q= weight.
        'order' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RESPONSE_COMPRESSION_ORDER', 'zstd,br,gzip'))
        ))),

        'levels' => [
            'gzip' => (int) env('RESPONSE_COMPRESSION_GZIP_LEVEL', 6),
            'br' => (int) env('RESPONSE_COMPRESSION_BROTLI_LEVEL', 5),
            'zstd' => (int) env('RESPONSE_COMPRESSION_ZSTD_LEVEL', 3),
        ],

        'skip_paths' => $skipPaths,
    ],

    'request' => [
        'enabled' => env('REQUEST_DECOMPRESSION_ENABLED', true),

        // Unpacked bodies bigger than this are refused with 413 (zip-bomb guard).
        'max_bytes' => (int) env('REQUEST_DECOMPRESSION_MAX_BYTES', 10485760),

        // Webhooks stay raw: their signatures are checked over the exact bytes.
        'skip_paths' => $skipPaths,
    ],

];
