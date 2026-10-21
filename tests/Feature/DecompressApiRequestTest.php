<?php

namespace Tests\Feature;

use App\Http\Middleware\DecompressApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DecompressApiRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Echo routes: report what the controller actually received.
        $echo = fn (Request $request) => response()->json([
            'input' => $request->all(),
            'raw_sha1' => sha1($request->getContent()),
            'content_encoding' => $request->header('Content-Encoding'),
        ]);

        Route::middleware('api')->post('api/v1/_test/echo', $echo);
        Route::middleware('api')->post('api/v1/webhooks/_test', $echo);
    }

    private function send(string $uri, string $body, array $headers): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']);

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private function payload(): array
    {
        return ['name' => 'Whispering Pines', 'notes' => str_repeat('quiet site ', 300)];
    }

    public function test_gzip_body_reaches_the_controller_unpacked(): void
    {
        $json = json_encode($this->payload());

        $this->send('/api/v1/_test/echo', gzencode($json), ['Content-Encoding' => 'gzip'])
            ->assertOk()
            ->assertJsonPath('input.name', 'Whispering Pines')
            ->assertJsonPath('raw_sha1', sha1($json))
            ->assertJsonPath('content_encoding', null);
    }

    public function test_deflate_body_reaches_the_controller_unpacked(): void
    {
        $json = json_encode($this->payload());

        $this->send('/api/v1/_test/echo', gzcompress($json), ['Content-Encoding' => 'deflate'])
            ->assertOk()
            ->assertJsonPath('input.name', 'Whispering Pines');
    }

    public function test_form_urlencoded_gzip_body_is_parsed(): void
    {
        $this->send('/api/v1/_test/echo', gzencode('name=Pines&site=12'), [
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->assertOk()->assertJsonPath('input.site', '12');
    }

    public function test_plain_body_is_untouched(): void
    {
        $this->send('/api/v1/_test/echo', json_encode(['name' => 'Plain']), [])
            ->assertOk()
            ->assertJsonPath('input.name', 'Plain');
    }

    public function test_broken_data_is_400(): void
    {
        $this->send('/api/v1/_test/echo', 'not gzip at all', ['Content-Encoding' => 'gzip'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_compressed_body');
    }

    public function test_cut_off_data_is_400(): void
    {
        $packed = gzencode(json_encode($this->payload()));

        $this->send('/api/v1/_test/echo', substr($packed, 0, (int) (strlen($packed) / 2)), ['Content-Encoding' => 'gzip'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_compressed_body');
    }

    public function test_body_bigger_than_max_bytes_is_413(): void
    {
        config(['compression.request.max_bytes' => 50000]);

        // ~10 MB of zeros packs to ~10 KB: a small zip bomb.
        $bomb = gzencode(str_repeat('0', 10 * 1024 * 1024), 9);

        $this->send('/api/v1/_test/echo', $bomb, ['Content-Encoding' => 'gzip'])
            ->assertStatus(413)
            ->assertJsonPath('reason', 'decompressed_body_too_large');
    }

    public function test_unsupported_encodings_are_415_with_handshake(): void
    {
        foreach (['br', 'zstd', 'compress'] as $encoding) {
            $this->send('/api/v1/_test/echo', 'xxx', ['Content-Encoding' => $encoding])
                ->assertStatus(415)
                ->assertJsonPath('reason', 'unsupported_content_encoding')
                ->assertHeader('Accept-Encoding', 'gzip, deflate');
        }
    }

    public function test_disabled_feature_is_415_and_advertises_identity(): void
    {
        config(['compression.request.enabled' => false]);

        $this->send('/api/v1/_test/echo', gzencode('{}'), ['Content-Encoding' => 'gzip'])
            ->assertStatus(415)
            ->assertJsonPath('reason', 'unsupported_content_encoding')
            ->assertHeader('Accept-Encoding', 'identity');
    }

    public function test_compressed_multipart_is_415(): void
    {
        $this->send('/api/v1/_test/echo', gzencode('--x--'), [
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'multipart/form-data; boundary=x',
        ])->assertStatus(415)->assertJsonPath('reason', 'multipart_not_supported');
    }

    public function test_error_answers_carry_cors_headers(): void
    {
        $this->send('/api/v1/_test/echo', 'xxx', ['Content-Encoding' => 'br', 'Origin' => 'http://localhost:3000'])
            ->assertStatus(415)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_api_responses_carry_the_handshake_header(): void
    {
        $this->send('/api/v1/_test/echo', '{}', [])->assertHeader('Accept-Encoding', 'gzip, deflate');
    }

    public function test_cors_exposes_the_handshake_header(): void
    {
        $this->send('/api/v1/_test/echo', '{}', ['Origin' => 'http://localhost:3000'])
            ->assertHeader('Access-Control-Expose-Headers', 'Accept-Encoding');
    }

    public function test_webhooks_stay_raw(): void
    {
        $packed = gzencode('{"type":"ContactCreate"}');

        $response = $this->send('/api/v1/webhooks/_test', $packed, ['Content-Encoding' => 'gzip'])
            ->assertOk()
            ->assertJsonPath('content_encoding', 'gzip');

        $response->assertJsonPath('raw_sha1', sha1($packed));
        $this->assertFalse($response->headers->has('Accept-Encoding'));
    }

    public function test_responses_are_compressed_end_to_end(): void
    {
        $response = $this->send('/api/v1/_test/echo', json_encode($this->payload()), ['Accept-Encoding' => 'gzip']);

        $response->assertOk()->assertHeader('Content-Encoding', 'gzip');
        $this->assertSame('Whispering Pines', json_decode(gzdecode($response->getContent()), true)['input']['name']);
    }

    public function test_enabled_flag_reads_config(): void
    {
        $this->assertTrue(DecompressApiRequest::enabled());
    }
}
