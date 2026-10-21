<?php

namespace Tests\Unit;

use App\Http\Middleware\CompressApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class CompressApiResponseTest extends TestCase
{
    private const ALL = ['gzip', 'br', 'zstd'];

    private const ORDER = ['zstd', 'br', 'gzip'];

    private function pass(string $acceptEncoding, SymfonyResponse $response, string $method = 'GET', string $path = '/api/v1/public/pages/about-us'): SymfonyResponse
    {
        $request = Request::create($path, $method);
        $request->headers->set('Accept-Encoding', $acceptEncoding);

        return (new CompressApiResponse)->handle($request, fn () => $response);
    }

    private function bigJson(): JsonResponse
    {
        return response()->json(['items' => array_fill(0, 200, ['name' => 'Whispering Pines', 'price' => 42])]);
    }

    // --- negotiation ---------------------------------------------------

    public function test_highest_weight_wins(): void
    {
        $this->assertSame('gzip', CompressApiResponse::negotiate('br;q=0.5, gzip;q=0.9, zstd;q=0.1', self::ALL, self::ORDER));
    }

    public function test_ties_are_broken_by_server_order(): void
    {
        $this->assertSame('zstd', CompressApiResponse::negotiate('gzip, br, zstd', self::ALL, self::ORDER));
        $this->assertSame('br', CompressApiResponse::negotiate('gzip, br, zstd', self::ALL, ['br', 'gzip', 'zstd']));
    }

    public function test_only_encodings_the_server_can_produce_are_picked(): void
    {
        $this->assertSame('gzip', CompressApiResponse::negotiate('br, zstd, gzip;q=0.2', ['gzip'], self::ORDER));
    }

    public function test_q_zero_means_never(): void
    {
        $this->assertNull(CompressApiResponse::negotiate('gzip;q=0', self::ALL, self::ORDER));
        $this->assertSame('br', CompressApiResponse::negotiate('*, zstd;q=0, gzip;q=0', self::ALL, self::ORDER));
    }

    public function test_nothing_acceptable_returns_null(): void
    {
        $this->assertNull(CompressApiResponse::negotiate('', self::ALL, self::ORDER));
        $this->assertNull(CompressApiResponse::negotiate('identity', self::ALL, self::ORDER));
    }

    // --- compression ---------------------------------------------------

    public function test_compresses_large_json_with_gzip(): void
    {
        $plain = $this->bigJson()->getContent();
        $response = $this->pass('gzip', $this->bigJson());

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame($plain, gzdecode($response->getContent()));
        $this->assertStringContainsString('Accept-Encoding', $response->headers->get('Vary'));
    }

    public function test_compresses_with_brotli_when_available(): void
    {
        if (! function_exists('brotli_compress')) {
            $this->markTestSkipped('brotli extension not installed');
        }

        $plain = $this->bigJson()->getContent();
        $response = $this->pass('gzip, br', $this->bigJson());

        $this->assertSame('br', $response->headers->get('Content-Encoding'));
        $this->assertSame($plain, brotli_uncompress($response->getContent()));
    }

    public function test_compresses_with_zstd_when_available(): void
    {
        if (! function_exists('zstd_compress')) {
            $this->markTestSkipped('zstd extension not installed');
        }

        $plain = $this->bigJson()->getContent();
        $response = $this->pass('gzip, br, zstd', $this->bigJson());

        $this->assertSame('zstd', $response->headers->get('Content-Encoding'));
        $this->assertSame($plain, zstd_uncompress($response->getContent()));
    }

    public function test_compresses_text_responses(): void
    {
        $response = $this->pass('gzip', new Response(str_repeat('campsite ', 500), 200, ['Content-Type' => 'text/plain; charset=UTF-8']));

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    public function test_merges_with_an_existing_vary_header(): void
    {
        $json = $this->bigJson();
        $json->headers->set('Vary', 'Origin');

        $this->assertSame('Origin, Accept-Encoding', $this->pass('gzip', $json)->headers->get('Vary'));
    }

    // --- skipped -------------------------------------------------------

    public function test_skips_small_bodies(): void
    {
        $response = $this->pass('gzip', response()->json(['ok' => true]));

        $this->assertFalse($response->headers->has('Content-Encoding'));
    }

    public function test_skips_head_and_options(): void
    {
        $this->assertFalse($this->pass('gzip', $this->bigJson(), 'HEAD')->headers->has('Content-Encoding'));
        $this->assertFalse($this->pass('gzip', $this->bigJson(), 'OPTIONS')->headers->has('Content-Encoding'));
    }

    public function test_skips_error_responses(): void
    {
        $error = $this->bigJson()->setStatusCode(422);

        $this->assertFalse($this->pass('gzip', $error)->headers->has('Content-Encoding'));
    }

    public function test_skips_streamed_and_file_responses(): void
    {
        $streamed = new StreamedResponse(fn () => print str_repeat('x', 5000), 200, ['Content-Type' => 'text/plain']);
        $this->assertFalse($this->pass('gzip', $streamed)->headers->has('Content-Encoding'));

        $path = tempnam(sys_get_temp_dir(), 'cmp');
        file_put_contents($path, str_repeat('{"a":1}', 1000));
        $file = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/json']);
        $this->assertFalse($this->pass('gzip', $file)->headers->has('Content-Encoding'));
        @unlink($path);
    }

    public function test_skips_binary_and_pdf_content(): void
    {
        $pdf = new Response(str_repeat('%PDF', 1000), 200, ['Content-Type' => 'application/pdf']);

        $this->assertFalse($this->pass('gzip', $pdf)->headers->has('Content-Encoding'));
    }

    public function test_skips_already_encoded_responses(): void
    {
        $json = $this->bigJson();
        $json->headers->set('Content-Encoding', 'br');
        $before = $json->getContent();

        $response = $this->pass('gzip', $json);

        $this->assertSame('br', $response->headers->get('Content-Encoding'));
        $this->assertSame($before, $response->getContent());
    }

    public function test_skips_no_transform(): void
    {
        $json = $this->bigJson();
        $json->headers->set('Cache-Control', 'no-transform');

        $this->assertFalse($this->pass('gzip', $json)->headers->has('Content-Encoding'));
    }

    public function test_skips_configured_paths(): void
    {
        $this->assertFalse($this->pass('gzip', $this->bigJson(), 'POST', '/api/v1/webhooks/ghl')->headers->has('Content-Encoding'));
        $this->assertFalse($this->pass('gzip', $this->bigJson(), 'GET', '/api/v1/settings/engage/callback')->headers->has('Content-Encoding'));
    }

    public function test_does_nothing_when_disabled(): void
    {
        config(['compression.response.enabled' => false]);

        $this->assertFalse($this->pass('gzip', $this->bigJson())->headers->has('Content-Encoding'));
    }

    public function test_sends_plain_when_client_accepts_nothing_usable(): void
    {
        $response = $this->pass('identity', $this->bigJson());

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', $response->headers->get('Vary'));
    }
}
