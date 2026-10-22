<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compresses large JSON/text API responses (gzip always; br and zstd only
 * when the PHP extension is installed), based on the client's
 * Accept-Encoding header and its q= weights.
 *
 * Prepended to the `api` group so it runs outermost and sees the final body.
 * See docs/http-compression.md.
 */
class CompressApiResponse
{
    /** Content types worth compressing. */
    private const COMPRESSIBLE_TYPES = [
        'application/json',
        'application/xml',
        'image/svg+xml',
    ];

    /**
     * Internal header set only by the superadmin compression test endpoint:
     * auto | none | gzip | br | zstd. Never sent to the client.
     */
    public const TEST_HEADER = 'X-Compression-Test';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has(self::TEST_HEADER)) {
            return $this->handleTest($request, $response);
        }

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $encoding = self::negotiate(
            (string) $request->headers->get('Accept-Encoding', ''),
            self::availableEncodings(),
            (array) config('compression.response.order', ['zstd', 'br', 'gzip'])
        );

        // The body varies by Accept-Encoding from here on, even when we end up sending it plain.
        $this->addVary($response);

        if ($encoding === null) {
            return $response;
        }

        $body = (string) $response->getContent();
        $packed = $this->encode($encoding, $body);

        if ($packed === null || strlen($packed) >= strlen($body)) {
            return $response;
        }

        $response->setContent($packed);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Content-Length', (string) strlen($packed));

        return $response;
    }

    /**
     * Encodings this PHP can produce, in no particular order.
     *
     * @return list<string>
     */
    public static function availableEncodings(): array
    {
        $available = [];

        if (function_exists('gzencode')) {
            $available[] = 'gzip';
        }
        if (function_exists('brotli_compress')) {
            $available[] = 'br';
        }
        if (function_exists('zstd_compress')) {
            $available[] = 'zstd';
        }

        return $available;
    }

    /**
     * Pick the encoding with the highest q= weight among those the client
     * accepts and the server can produce. Ties are broken by $order.
     * q=0 means never. "*" applies to every encoding not named explicitly.
     *
     * @param  list<string>  $available
     * @param  list<string>  $order
     */
    public static function negotiate(string $acceptEncoding, array $available, array $order): ?string
    {
        $weights = [];
        $wildcard = null;

        foreach (explode(',', $acceptEncoding) as $part) {
            $pieces = array_map('trim', explode(';', $part));
            $name = strtolower(array_shift($pieces));
            if ($name === '') {
                continue;
            }

            $q = 1.0;
            foreach ($pieces as $param) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }

            if ($name === '*') {
                $wildcard = $q;
            } else {
                // If a name is listed twice, keep the higher weight.
                $weights[$name] = max($weights[$name] ?? 0.0, $q);
            }
        }

        $order = array_map('strtolower', $order);
        $best = null;
        $bestQ = 0.0;
        $bestRank = PHP_INT_MAX;

        foreach ($available as $encoding) {
            $q = $weights[$encoding] ?? $wildcard;
            if ($q === null || $q <= 0.0) {
                continue;
            }

            $rank = array_search($encoding, $order, true);
            $rank = $rank === false ? PHP_INT_MAX - 1 : $rank;

            if ($q > $bestQ || ($q === $bestQ && $rank < $bestRank)) {
                $best = $encoding;
                $bestQ = $q;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! config('compression.response.enabled', true)) {
            return false;
        }

        if ($request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return false;
        }

        if (self::isSkippedPath($request, (array) config('compression.response.skip_paths', []))) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if (! $response->isSuccessful()) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if ($response->headers->hasCacheControlDirective('no-transform')) {
            return false;
        }

        if ($response->headers->has('Content-Disposition')
            && str_starts_with(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment')) {
            return false;
        }

        if (! $this->isCompressibleType((string) $response->headers->get('Content-Type', ''))) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content)
            && strlen($content) >= (int) config('compression.response.min_bytes', 1024);
    }

    /** @param  list<string>  $patterns */
    public static function isSkippedPath(Request $request, array $patterns): bool
    {
        return $patterns !== [] && $request->is(...$patterns);
    }

    private function isCompressibleType(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if ($type === '') {
            return false;
        }

        return str_starts_with($type, 'text/')
            || in_array($type, self::COMPRESSIBLE_TYPES, true)
            || str_ends_with($type, '+json')
            || str_contains($type, 'javascript');
    }

    private function encode(string $encoding, string $body): ?string
    {
        $levels = (array) config('compression.response.levels', []);

        $packed = match ($encoding) {
            'gzip' => gzencode($body, (int) ($levels['gzip'] ?? 6)),
            'br' => function_exists('brotli_compress') ? brotli_compress($body, (int) ($levels['br'] ?? 5)) : false,
            'zstd' => function_exists('zstd_compress') ? zstd_compress($body, (int) ($levels['zstd'] ?? 3)) : false,
            default => false,
        };

        return is_string($packed) ? $packed : null;
    }

    /**
     * Superadmin compression test: compress with the requested encoding (or
     * none, or the normal negotiation for "auto") and report what was done in
     * X-Compression-* headers. Asks proxies not to compress the body again
     * (no-transform), so the browser sees exactly what the app sent.
     */
    private function handleTest(Request $request, Response $response): Response
    {
        $mode = strtolower(trim((string) $response->headers->get(self::TEST_HEADER)));
        $response->headers->remove(self::TEST_HEADER);

        $body = (string) $response->getContent();
        $accepted = (string) $request->headers->get('Accept-Encoding', '');
        $available = self::availableEncodings();
        $encoding = null;
        $note = null;

        if ($mode === 'none') {
            $note = 'Compression switched off for this request.';
        } elseif ($mode === 'auto') {
            if (! config('compression.response.enabled', true)) {
                $note = 'Response compression is disabled (RESPONSE_COMPRESSION_ENABLED).';
            } elseif (strlen($body) < (int) config('compression.response.min_bytes', 1024)) {
                $note = 'Body is smaller than RESPONSE_COMPRESSION_MIN_BYTES.';
            } else {
                $encoding = self::negotiate($accepted, $available, (array) config('compression.response.order', ['zstd', 'br', 'gzip']));
                $note = $encoding === null ? 'The browser and the server have no encoding in common.' : null;
            }
        } elseif (! in_array($mode, $available, true)) {
            $note = "This server's PHP cannot produce {$mode}.";
        } elseif (self::negotiate($accepted, [$mode], [$mode]) === null) {
            $note = "The browser did not offer {$mode} in Accept-Encoding.";
        } else {
            $encoding = $mode;
        }

        $sent = $body;
        $ms = 0.0;

        if ($encoding !== null) {
            $started = hrtime(true);
            $packed = $this->encode($encoding, $body);
            $ms = (hrtime(true) - $started) / 1e6;

            if ($packed === null) {
                $note = "{$encoding} compression failed.";
                $encoding = null;
            } elseif ($mode === 'auto' && strlen($packed) >= strlen($body)) {
                // Same rule as normal responses.
                $note = 'Compressed body was not smaller, so it was sent plain.';
                $encoding = null;
            } else {
                $sent = $packed;
            }
        }

        if ($encoding !== null) {
            $response->setContent($sent);
            $response->headers->set('Content-Encoding', $encoding);
            $response->headers->set('Content-Length', (string) strlen($sent));
        }

        $this->addVary($response);
        $response->headers->set('Cache-Control', 'no-store, no-transform');
        $response->headers->set('Timing-Allow-Origin', '*');
        $response->headers->set('X-Compression-Encoding', $encoding ?? 'identity');
        $response->headers->set('X-Compression-Original-Bytes', (string) strlen($body));
        $response->headers->set('X-Compression-Sent-Bytes', (string) strlen($sent));
        $response->headers->set('X-Compression-Ms', number_format($ms, 3, '.', ''));
        if ($encoding !== null) {
            $level = (int) (((array) config('compression.response.levels', []))[$encoding] ?? 0);
            $response->headers->set('X-Compression-Level', (string) $level);
        }
        if ($note !== null) {
            $response->headers->set('X-Compression-Note', $note);
        }

        return $response;
    }

    private function addVary(Response $response): void
    {
        $existing = array_filter(array_map(
            'trim',
            explode(',', implode(',', $response->headers->all('Vary')))
        ));

        foreach ($existing as $value) {
            if ($value === '*' || strcasecmp($value, 'Accept-Encoding') === 0) {
                return;
            }
        }

        $existing[] = 'Accept-Encoding';
        $response->headers->set('Vary', implode(', ', $existing));
    }
}
