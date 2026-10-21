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

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

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
