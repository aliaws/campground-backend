<?php

namespace Tests\Feature;

use App\Http\Middleware\DecompressApiRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class CompressionTestEndpointsTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private function upload(string $body, array $headers): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/octet-stream']);

        return $this->call('POST', '/api/v1/superadmin/compression-test/upload', [], [], [], $server, $body);
    }

    private function bigResponse(string $encoding, array $extra = []): TestResponse
    {
        $query = http_build_query(['source' => 'products', 'size_mb' => 0.2, 'encoding' => $encoding] + $extra);

        return $this->get("/api/v1/superadmin/compression-test/response?{$query}", $this->superadminHeaders() + ['Accept-Encoding' => 'gzip, deflate']);
    }

    public function test_only_the_superadmin_can_run_it(): void
    {
        $org = $this->makeOrganization();
        $headers = $this->tokenFor($this->makeUser(['owner'], $org), $org);

        $this->getJson('/api/v1/superadmin/compression-test', $headers)->assertForbidden();
        $this->upload('abc', $headers)->assertForbidden();
        $this->getJson('/api/v1/superadmin/compression-test')->assertUnauthorized();
        $this->getJson('/api/v1/superadmin/compression-test', $this->superadminHeaders())
            ->assertOk()
            ->assertJsonPath('data.request.encodings', DecompressApiRequest::encodings());
    }

    public function test_plain_upload_is_reported_as_received(): void
    {
        $body = random_bytes(5000);

        $this->upload($body, $this->superadminHeaders())
            ->assertOk()
            ->assertJsonPath('data.encoding', 'identity')
            ->assertJsonPath('data.wire_bytes', 5000)
            ->assertJsonPath('data.plain_bytes', 5000)
            ->assertJsonPath('data.crc32', sprintf('%08x', crc32($body)));
    }

    public function test_gzip_upload_is_unpacked_and_both_sizes_reported(): void
    {
        $body = str_repeat('campsite booking ', 2000);
        $packed = gzencode($body);

        $this->upload($packed, $this->superadminHeaders() + ['Content-Encoding' => 'gzip'])
            ->assertOk()
            ->assertJsonPath('data.encoding', 'gzip')
            ->assertJsonPath('data.wire_bytes', strlen($packed))
            ->assertJsonPath('data.plain_bytes', strlen($body))
            ->assertJsonPath('data.crc32', sprintf('%08x', crc32($body)));
    }

    public function test_uploads_leave_no_files_behind(): void
    {
        $files = fn () => collect(File::allFiles(storage_path('app')))
            ->map(fn ($file) => $file->getPathname().'|'.$file->getSize())
            ->sort()
            ->values()
            ->all();

        $before = $files();
        $body = random_bytes(200000);

        $this->upload($body, $this->superadminHeaders())->assertOk();
        $this->upload(gzencode($body), $this->superadminHeaders() + ['Content-Encoding' => 'gzip'])->assertOk();
        if (function_exists('brotli_compress')) {
            $this->upload(brotli_compress($body, 5), $this->superadminHeaders() + ['Content-Encoding' => 'br'])->assertOk();
        }
        if (function_exists('zstd_compress')) {
            $this->upload(zstd_compress($body, 3), $this->superadminHeaders() + ['Content-Encoding' => 'zstd'])->assertOk();
        }

        $this->assertSame($before, $files());
    }

    public function test_brotli_and_zstd_uploads_are_unpacked(): void
    {
        $body = str_repeat('campsite booking ', 2000);
        $tested = 0;

        foreach (['br' => fn ($b) => brotli_compress($b, 5), 'zstd' => fn ($b) => zstd_compress($b, 3)] as $encoding => $pack) {
            if (! in_array($encoding, DecompressApiRequest::encodings(), true)) {
                continue;
            }
            $packed = $pack($body);
            $this->upload($packed, $this->superadminHeaders() + ['Content-Encoding' => $encoding])
                ->assertOk()
                ->assertJsonPath('data.encoding', $encoding)
                ->assertJsonPath('data.wire_bytes', strlen($packed))
                ->assertJsonPath('data.plain_bytes', strlen($body))
                ->assertJsonPath('data.crc32', sprintf('%08x', crc32($body)));
            $tested++;
        }

        if ($tested === 0) {
            $this->markTestSkipped('Neither the brotli nor the zstd PHP extension is installed.');
        }
    }

    public function test_response_with_brotli_and_zstd(): void
    {
        $this->makeProduct($this->makeOrganization());
        $tested = 0;

        foreach (['br' => 'brotli_uncompress', 'zstd' => 'zstd_uncompress'] as $encoding => $unpack) {
            if (! function_exists($unpack)) {
                continue;
            }
            $response = $this->get(
                "/api/v1/superadmin/compression-test/response?source=products&size_mb=0.2&encoding={$encoding}",
                $this->superadminHeaders() + ['Accept-Encoding' => 'gzip, deflate, br, zstd'],
            )->assertOk()->assertHeader('Content-Encoding', $encoding);

            $this->assertGreaterThan(0, json_decode($unpack($response->getContent()), true)['meta']['rows']);
            $tested++;
        }

        if ($tested === 0) {
            $this->markTestSkipped('Neither the brotli nor the zstd PHP extension is installed.');
        }
    }

    public function test_response_without_compression_is_plain(): void
    {
        $this->makeProduct($this->makeOrganization());

        $response = $this->bigResponse('none')->assertOk();

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $response->assertHeader('X-Compression-Encoding', 'identity');
        $this->assertGreaterThanOrEqual(0.2 * 1048576, (int) $response->headers->get('X-Compression-Original-Bytes'));
        $this->assertSame(
            $response->headers->get('X-Compression-Original-Bytes'),
            $response->headers->get('X-Compression-Sent-Bytes'),
        );
        $this->assertSame('products', json_decode($response->getContent(), true)['meta']['source']);
    }

    public function test_response_with_gzip_is_compressed_and_reported(): void
    {
        $this->makeProduct($this->makeOrganization());

        $response = $this->bigResponse('gzip')->assertOk();

        $response->assertHeader('Content-Encoding', 'gzip')->assertHeader('X-Compression-Encoding', 'gzip');
        $this->assertStringContainsString('no-transform', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(strlen($response->getContent()), (int) $response->headers->get('X-Compression-Sent-Bytes'));
        $this->assertLessThan(
            (int) $response->headers->get('X-Compression-Original-Bytes'),
            (int) $response->headers->get('X-Compression-Sent-Bytes'),
        );
        $this->assertGreaterThan(0, json_decode(gzdecode($response->getContent()), true)['meta']['rows']);
        $this->assertFalse($response->headers->has('X-Compression-Test'));
    }

    public function test_an_encoding_the_browser_did_not_offer_is_not_used(): void
    {
        $this->makeProduct($this->makeOrganization());

        $response = $this->get(
            '/api/v1/superadmin/compression-test/response?source=products&size_mb=0.2&encoding=gzip',
            $this->superadminHeaders() + ['Accept-Encoding' => 'identity'],
        )->assertOk();

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertNotNull($response->headers->get('X-Compression-Note'));
    }

    public function test_normal_responses_carry_no_test_headers(): void
    {
        $response = $this->getJson('/api/v1/superadmin/compression-test', $this->superadminHeaders() + ['Accept-Encoding' => 'gzip']);

        $this->assertFalse($response->headers->has('X-Compression-Encoding'));
    }
}
