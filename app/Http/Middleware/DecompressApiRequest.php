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

    /** Content-Encoding value => zlib encoding constant. */
    private const SUPPORTED = [
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
            $error = $this->decompress($request, $encoding);

            if ($error !== null) {
                return $this->withHandshake($this->withCors($error, $request));
            }
        }

        return $this->withHandshake($next($request));
    }

    public static function enabled(): bool
    {
        return (bool) config('compression.request.enabled', true);
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
        if (! self::enabled() || ! isset(self::SUPPORTED[$encoding])) {
            return $this->error(415, 'unsupported_content_encoding', "Content-Encoding \"{$encoding}\" is not supported.");
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->error(415, 'multipart_not_supported', 'Compressed multipart/form-data bodies are not supported.');
        }

        $maxBytes = (int) config('compression.request.max_bytes', 10485760);
        $packed = (string) $request->getContent();

        $inflate = @inflate_init(self::SUPPORTED[$encoding]);
        if ($inflate === false) {
            return $this->error(400, 'invalid_compressed_body', 'The compressed request body could not be read.');
        }

        $plain = '';
        $length = strlen($packed);

        for ($offset = 0; $offset < $length; $offset += self::CHUNK_BYTES) {
            $last = $offset + self::CHUNK_BYTES >= $length;
            $chunk = @inflate_add($inflate, substr($packed, $offset, self::CHUNK_BYTES), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($chunk === false) {
                return $this->error(400, 'invalid_compressed_body', 'The compressed request body is corrupt.');
            }

            $plain .= $chunk;

            if (strlen($plain) > $maxBytes) {
                return $this->error(413, 'decompressed_body_too_large', "The unpacked request body is larger than {$maxBytes} bytes.");
            }
        }

        // An empty packed body, or a stream cut off before its end marker.
        if ($length === 0 || inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            return $this->error(400, 'invalid_compressed_body', 'The compressed request body is incomplete.');
        }

        $this->replaceBody($request, $plain, $contentType);

        return null;
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
        $response->headers->set('Accept-Encoding', self::enabled() ? 'gzip, deflate' : 'identity');

        return $response;
    }

    /** Error answers are built before the CORS middleware runs, so add its headers here. */
    private function withCors(Response $response, SymfonyRequest $request): Response
    {
        $this->cors->setOptions((array) config('cors', []));

        return $this->cors->addActualRequestHeaders($response, $request);
    }
}
