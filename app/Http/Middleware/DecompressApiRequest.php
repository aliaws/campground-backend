<?php

namespace App\Http\Middleware;

use Closure;
use Fruitcake\Cors\CorsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unpacks gzip/deflate request bodies sent by the browser app, so the rest
 * of the app (TrimStrings, validation, controllers) sees a normal plain body.
 *
 * Also advertises which request encodings the API accepts by adding an
 * "Accept-Encoding" header to every API response (RFC 7694), which is how the
 * frontend learns it may compress.
 *
 * Runs as the first global middleware, i.e. before CORS — so its own error
 * answers add the CORS headers themselves. See docs/http-compression.md.
 */
class DecompressApiRequest
{
    private const CHUNK_BYTES = 4096;

    /** Request attribute holding {encoding, wire_bytes, decompress_ms} once a body was unpacked. */
    public const ATTRIBUTE = 'compression.request';

    /** Content-Encoding value => zlib encoding constant. */
    private const ZLIB = [
        'gzip' => ZLIB_ENCODING_GZIP,
        'x-gzip' => ZLIB_ENCODING_GZIP,
        'deflate' => ZLIB_ENCODING_DEFLATE,
    ];

    public function __construct(private CorsService $cors) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->applies($request)) {
            return $next($request);
        }

        $encoding = strtolower(trim((string) $request->headers->get('Content-Encoding', '')));

        if ($encoding !== '' && $encoding !== 'identity') {
            $wireBytes = strlen((string) $request->getContent());
            $started = hrtime(true);
            $error = $this->decompress($request, $encoding);

            if ($error !== null) {
                return $this->withHandshake($this->withCors($error, $request));
            }

            // What arrived on the wire and how long unpacking took (read by
            // the superadmin compression test page).
            $request->attributes->set(self::ATTRIBUTE, [
                'encoding' => $encoding,
                'wire_bytes' => $wireBytes,
                'decompress_ms' => (hrtime(true) - $started) / 1e6,
            ]);
        }

        return $this->withHandshake($next($request));
    }

    public static function enabled(): bool
    {
        return (bool) config('compression.request.enabled', true);
    }

    /**
     * Request encodings this PHP can unpack: gzip and deflate always, br and
     * zstd only when the brotli / zstd extension is installed.
     *
     * @return list<string>
     */
    public static function encodings(): array
    {
        $encodings = ['gzip', 'deflate'];

        if (function_exists('brotli_uncompress_init')) {
            $encodings[] = 'br';
        }
        if (function_exists('zstd_uncompress_init')) {
            $encodings[] = 'zstd';
        }

        return $encodings;
    }

    private static function supports(string $encoding): bool
    {
        return isset(self::ZLIB[$encoding]) || in_array($encoding, self::encodings(), true);
    }

    private function applies(Request $request): bool
    {
        return $request->is('api/*')
            && ! CompressApiResponse::isSkippedPath($request, (array) config('compression.request.skip_paths', []));
    }

    /**
     * Replace the request body with its unpacked form. Returns an error
     * response when the body can't (or mustn't) be unpacked, null on success.
     */
    private function decompress(Request $request, string $encoding): ?JsonResponse
    {
        if (! self::enabled() || ! self::supports($encoding)) {
            return $this->error(415, 'unsupported_content_encoding', "Content-Encoding \"{$encoding}\" is not supported.");
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->error(415, 'multipart_not_supported', 'Compressed multipart/form-data bodies are not supported.');
        }

        $maxBytes = (int) config('compression.request.max_bytes', 10485760);
        $packed = (string) $request->getContent();

        $stream = $this->openStream($encoding);
        if ($stream === null) {
            return $this->error(400, 'invalid_compressed_body', 'The compressed request body could not be read.');
        }

        $plain = '';
        $length = strlen($packed);

        // Unpack piece by piece so an oversized body is refused before it is
        // fully unpacked (zip-bomb guard).
        for ($offset = 0; $offset < $length; $offset += self::CHUNK_BYTES) {
            $last = $offset + self::CHUNK_BYTES >= $length;
            $chunk = $stream['add'](substr($packed, $offset, self::CHUNK_BYTES), $last);

            if ($chunk === false) {
                return $this->error(400, 'invalid_compressed_body', 'The compressed request body is corrupt.');
            }

            $plain .= $chunk;

            if (strlen($plain) > $maxBytes) {
                return $this->error(413, 'decompressed_body_too_large', "The unpacked request body is larger than {$maxBytes} bytes.");
            }
        }

        // An empty packed body, or a stream cut off before its end marker.
        if ($length === 0 || ! $stream['complete']()) {
            return $this->error(400, 'invalid_compressed_body', 'The compressed request body is incomplete.');
        }

        $this->replaceBody($request, $plain, $contentType);

        return null;
    }

    /**
     * A streaming unpacker for one body: "add" takes the next piece of packed
     * data (and whether it is the last) and returns the unpacked bytes or
     * false; "complete" says whether the stream ended properly.
     *
     * @return array{add: Closure(string, bool): (string|false), complete: Closure(): bool}|null
     */
    private function openStream(string $encoding): ?array
    {
        if (isset(self::ZLIB[$encoding])) {
            $context = @inflate_init(self::ZLIB[$encoding]);

            return $context === false ? null : [
                'add' => fn (string $data, bool $last) => @inflate_add($context, $data, $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH),
                'complete' => fn () => inflate_get_status($context) === ZLIB_STREAM_END,
            ];
        }

        if ($encoding === 'br') {
            $context = @brotli_uncompress_init();
            $packed = '';

            return $context === false ? null : [
                'add' => function (string $data, bool $last) use ($context, &$packed) {
                    $packed .= $data;

                    return @brotli_uncompress_add($context, $data, $last ? BROTLI_FINISH : BROTLI_PROCESS);
                },
                // The streaming decoder hands back a cut-off stream as if it
                // were whole; the one-shot decoder refuses it. Safe to run now:
                // the streamed pass already proved the result fits max_bytes.
                'complete' => function () use (&$packed) {
                    return @brotli_uncompress($packed) !== false;
                },
            ];
        }

        if ($encoding === 'zstd') {
            $context = @zstd_uncompress_init();
            $packed = '';
            $unpacked = 0;

            return $context === false ? null : [
                'add' => function (string $data, bool $last) use ($context, &$packed, &$unpacked) {
                    if (strlen($packed) < 18) {
                        $packed .= substr($data, 0, 18 - strlen($packed));
                    }
                    $out = @zstd_uncompress_add($context, $data);
                    $unpacked += is_string($out) ? strlen($out) : 0;

                    return $out;
                },
                // Neither zstd decoder notices a cut-off frame, so compare
                // against the original size the frame header declares.
                'complete' => function () use (&$packed, &$unpacked) {
                    $declared = self::zstdDeclaredSize($packed);

                    return $declared === null || $unpacked >= $declared;
                },
            ];
        }

        return null;
    }

    /**
     * The content size a zstd frame header declares (RFC 8878, 3.1.1.1), or
     * null when the frame doesn't say or the data isn't a zstd frame.
     */
    public static function zstdDeclaredSize(string $header): ?int
    {
        if (strlen($header) < 6 || substr($header, 0, 4) !== "\x28\xB5\x2F\xFD") {
            return null;
        }

        $descriptor = ord($header[4]);
        $singleSegment = ($descriptor >> 5) & 1;
        $sizeBytes = [$singleSegment ? 1 : 0, 2, 4, 8][$descriptor >> 6];
        if ($sizeBytes === 0) {
            return null;
        }

        $offset = 5 + ($singleSegment ? 0 : 1) + [0, 1, 2, 4][$descriptor & 3];
        if (strlen($header) < $offset + $sizeBytes) {
            return null;
        }

        $size = 0;
        for ($i = $sizeBytes - 1; $i >= 0; $i--) {
            $size = ($size << 8) | ord($header[$offset + $i]);
        }

        return $sizeBytes === 2 ? $size + 256 : $size;
    }

    private function replaceBody(Request $request, string $plain, string $contentType): void
    {
        // Symfony keeps the raw body in a protected property; there is no public setter.
        (function (string $content) {
            $this->content = $content;
        })->call($request, $plain);

        $request->headers->remove('Content-Encoding');
        $request->server->remove('HTTP_CONTENT_ENCODING');
        $request->headers->set('Content-Length', (string) strlen($plain));
        $request->server->set('CONTENT_LENGTH', (string) strlen($plain));

        // Parse the input again from the plain body.
        $request->setJson(null);

        if ($request->isJson()) {
            $request->request->replace($request->json()->all());
            $request->setJson($request->request);
        } elseif (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($plain, $fields);
            $request->request->replace($fields);
        }
    }

    private function error(int $status, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    /** RFC 7694: tell the client which request encodings this API accepts. */
    private function withHandshake(Response $response): Response
    {
        $response->headers->set('Accept-Encoding', self::enabled() ? implode(', ', self::encodings()) : 'identity');

        return $response;
    }

    /** Error answers are built before the CORS middleware runs, so add its headers here. */
    private function withCors(Response $response, SymfonyRequest $request): Response
    {
        $this->cors->setOptions((array) config('cors', []));

        return $this->cors->addActualRequestHeaders($response, $request);
    }
}
