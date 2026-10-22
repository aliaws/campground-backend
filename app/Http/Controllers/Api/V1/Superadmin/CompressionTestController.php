<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CompressApiResponse;
use App\Http\Middleware\DecompressApiRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Superadmin compression test page: measures request-body compression
 * (uploads sent plain vs gzip/deflate) and response compression (a large
 * JSON response built from database rows, sent plain vs gzip/br/zstd).
 * Nothing is stored. See docs/http-compression.md.
 */
class CompressionTestController extends Controller
{
    /** Tables a test response can be built from (label => table). */
    private const SOURCES = [
        'bookings' => 'engage_bookings',
        'customers' => 'engage_customers',
        'products' => 'engage_products',
        'campsite_transactions' => 'engage_rental_transactions',
    ];

    /** Real rows read per table; larger responses repeat them. */
    private const MAX_UNIQUE_ROWS = 2000;

    private const MAX_RESPONSE_MB = 50;

    public function info(): JsonResponse
    {
        $counts = [];
        foreach (self::SOURCES as $source => $table) {
            $counts[$source] = DB::table($table)->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'server_encodings' => CompressApiResponse::availableEncodings(),
                'response' => [
                    'enabled' => (bool) config('compression.response.enabled', true),
                    'min_bytes' => (int) config('compression.response.min_bytes', 1024),
                    'order' => array_values((array) config('compression.response.order', [])),
                    'levels' => (array) config('compression.response.levels', []),
                ],
                'request' => [
                    'enabled' => DecompressApiRequest::enabled(),
                    'encodings' => DecompressApiRequest::enabled() ? DecompressApiRequest::encodings() : [],
                    'max_bytes' => (int) config('compression.request.max_bytes', 10485760),
                ],
                'php' => [
                    'version' => PHP_VERSION,
                    'post_max_size' => ini_get('post_max_size'),
                    'post_max_bytes' => $this->iniBytes((string) ini_get('post_max_size')),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => (int) ini_get('max_execution_time'),
                ],
                'server_software' => (string) request()->server('SERVER_SOFTWARE', ''),
                'sources' => $counts,
                'max_response_mb' => self::MAX_RESPONSE_MB,
            ],
        ]);
    }

    /**
     * One upload chunk, raw bytes (application/octet-stream), plain or with
     * Content-Encoding gzip/deflate. The decompress middleware has already
     * unpacked it; report what arrived and a CRC32 of the unpacked bytes so
     * the page can confirm nothing was lost. The bytes only ever live in this
     * request's memory: nothing is written to disk, storage or the database.
     */
    public function upload(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $plain = (string) $request->getContent();

        if ($plain === '') {
            return response()->json(['success' => false, 'message' => 'The request body is empty.'], 422);
        }

        $unpacked = $request->attributes->get(DecompressApiRequest::ATTRIBUTE);

        return response()->json([
            'success' => true,
            'data' => [
                'encoding' => $unpacked['encoding'] ?? 'identity',
                'wire_bytes' => $unpacked['wire_bytes'] ?? strlen($plain),
                'plain_bytes' => strlen($plain),
                'decompress_ms' => round($unpacked['decompress_ms'] ?? 0.0, 3),
                'crc32' => sprintf('%08x', crc32($plain)),
                'handler_ms' => round((hrtime(true) - $started) / 1e6, 3),
                'server_ms' => defined('LARAVEL_START') ? round((microtime(true) - LARAVEL_START) * 1000, 3) : null,
            ],
        ]);
    }

    /**
     * A large JSON response built from real database rows. When "repeat" is
     * on, the rows are repeated (each copy tagged with _copy) until the body
     * reaches the requested size. The middleware compresses it with the
     * requested encoding and reports the result in X-Compression-* headers.
     */
    public function response(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:mixed,'.implode(',', array_keys(self::SOURCES))],
            'size_mb' => ['required', 'numeric', 'min:0.1', 'max:'.self::MAX_RESPONSE_MB],
            'repeat' => ['sometimes', 'boolean'],
            'encoding' => ['required', 'in:auto,none,gzip,br,zstd'],
        ]);

        $this->raiseLimits();
        $started = hrtime(true);

        $sources = $data['source'] === 'mixed' ? array_keys(self::SOURCES) : [$data['source']];
        $rows = $this->encodedRows($sources);

        if ($rows === []) {
            return response()->json(['success' => false, 'message' => 'There are no rows in this table to build a response from.'], 422);
        }

        $target = (int) round((float) $data['size_mb'] * 1048576);
        $repeat = (bool) ($data['repeat'] ?? true);

        $parts = [];
        $bytes = 0;
        $copy = 0;
        do {
            foreach ($rows as $row) {
                $part = $row['prefix'].',"_copy":'.$copy.'}';
                $parts[] = $part;
                $bytes += strlen($part) + 1;
                if ($repeat && $bytes >= $target) {
                    break 2;
                }
            }
            $copy++;
        } while ($repeat && $bytes < $target);

        $meta = [
            'source' => $data['source'],
            'rows' => count($parts),
            'unique_rows' => count($rows),
            'copies' => $copy + ($repeat ? 1 : 0),
            'repeated' => $repeat,
            'requested_bytes' => $repeat ? $target : null,
        ];

        $json = '{"success":true,"meta":'.json_encode($meta).',"data":['.implode(',', $parts).']}';
        unset($parts);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'X-Build-Ms' => number_format((hrtime(true) - $started) / 1e6, 3, '.', ''),
            CompressApiResponse::TEST_HEADER => $data['encoding'],
        ]);
    }

    /**
     * Each real row encoded once as JSON without its closing brace, ready
     * for a per-copy suffix.
     *
     * @param  list<string>  $sources
     * @return list<array{prefix: string}>
     */
    private function encodedRows(array $sources): array
    {
        $perTable = (int) ceil(self::MAX_UNIQUE_ROWS / count($sources));
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $rows = [];

        foreach ($sources as $source) {
            $records = DB::table(self::SOURCES[$source])->orderBy('id')->limit($perTable)->get();

            foreach ($records as $record) {
                $encoded = json_encode(['_source' => $source] + (array) $record, $flags);
                if (is_string($encoded)) {
                    $rows[] = ['prefix' => substr($encoded, 0, -1)];
                }
            }
        }

        return $rows;
    }

    /** A 50 MB body is held a few times over while it is built and compressed. */
    private function raiseLimits(): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && $this->iniBytes($limit) < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }
        @set_time_limit(120);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
