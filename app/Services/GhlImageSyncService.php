<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GhlImageSyncService
{
    public function __construct(private GhlClient $client) {}

    // ── Pull: GHL → Laravel ───────────────────────────────────────────────────

    /**
     * Download the image at $ghlImageUrl, store it in local public storage,
     * and update the product record.
     *
     * Skip logic: if ghl_image_url already equals $ghlImageUrl the image has
     * not changed on GHL since the last pull — nothing to do.
     *
     * Non-blocking: failures are logged but do not abort the product pull.
     */
    public function pullImageFromGhl(Product $product, string $ghlImageUrl): void
    {
        if (! $ghlImageUrl) {
            return;
        }

        // Nothing changed on GHL since last pull
        if ($product->ghl_image_url === $ghlImageUrl && $product->image) {
            Log::info('GHL image pull skipped — already in sync', [
                'product_id' => $product->id,
                'direction'  => 'pull',
                'ghl_url'    => $ghlImageUrl,
            ]);

            return;
        }

        Log::info('GHL image pull started', [
            'product_id' => $product->id,
            'direction'  => 'pull',
            'ghl_url'    => $ghlImageUrl,
        ]);

        try {
            $response = Http::timeout(30)->get($ghlImageUrl);

            if ($response->failed()) {
                throw new \RuntimeException("Download failed: HTTP {$response->status()}");
            }

            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $ext = $this->extensionFromMime($contentType)
                ?? pathinfo(parse_url($ghlImageUrl, PHP_URL_PATH), PATHINFO_EXTENSION)
                ?: 'jpg';

            $filename = Str::random(40) . '.' . ltrim($ext, '.');
            Storage::disk('public')->put("products/{$filename}", $response->body());

            $relativePath = '/storage/products/' . $filename;

            $product->update([
                'image'         => $relativePath,
                'ghl_image_url' => $ghlImageUrl,
            ]);

            Log::info('GHL image pull succeeded', [
                'product_id' => $product->id,
                'direction'  => 'pull',
                'local_path' => $relativePath,
            ]);
        } catch (\Exception $e) {
            Log::error('GHL image pull failed', [
                'product_id' => $product->id,
                'direction'  => 'pull',
                'ghl_url'    => $ghlImageUrl,
                'error'      => $e->getMessage(),
            ]);
            // Non-blocking — image failure should not abort the product pull
        }
    }

    // ── Push: Laravel → GHL ───────────────────────────────────────────────────

    /**
     * Resolve the product's local image, upload it to GHL's media library,
     * persist the returned CDN URL in ghl_image_url, and return the CDN URL
     * for inclusion in the product push payload.
     *
     * Returns null when:
     *  - no image is set on the product
     *  - the local file is missing from disk
     *  - the GHL upload fails
     * In all null cases the caller should omit the image field from the payload
     * rather than sending a broken or relative URL.
     *
     * Cache hit: if ghl_image_url is already a cdn.filesafe.space URL the image
     * was already uploaded and the local file has not changed (ProductService::
     * uploadImage() clears ghl_image_url whenever the user replaces the file),
     * so the cached URL is returned without re-uploading.
     */
    public function pushImageToGhl(Product $product): ?string
    {
        if (! $product->image) {
            return null;
        }

        // Cache hit — local image unchanged since last push
        if ($product->ghl_image_url && str_contains($product->ghl_image_url, 'cdn.filesafe.space')) {
            return $product->ghl_image_url;
        }

        // Image is itself already a GHL CDN URL — cache and return
        if (str_contains($product->image, 'cdn.filesafe.space')) {
            $product->update(['ghl_image_url' => $product->image]);

            return $product->image;
        }

        // Local storage path — upload the file
        if (str_starts_with($product->image, '/storage/')) {
            return $this->uploadLocalImage($product);
        }

        // Full public HTTP URL (e.g. external CDN already) — use as-is
        if (str_starts_with($product->image, 'http')) {
            return $product->image;
        }

        // Anything else (bare filename, unknown scheme) — skip; never send to GHL
        Log::warning('GHL image push skipped — unrecognised image format', [
            'product_id' => $product->id,
            'image'      => $product->image,
        ]);

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function uploadLocalImage(Product $product): ?string
    {
        $disk         = Storage::disk('public');
        $relativePath = ltrim(substr($product->image, strlen('/storage')), '/');

        if (! $disk->exists($relativePath)) {
            Log::warning('GHL image push skipped — local file not found', [
                'product_id' => $product->id,
                'path'       => $product->image,
            ]);

            return null;
        }

        $localPath = $disk->path($relativePath);
        $filename  = basename($localPath);
        $mimeType  = mime_content_type($localPath) ?: 'image/jpeg';

        Log::info('GHL image push started', [
            'product_id' => $product->id,
            'direction'  => 'push',
            'local_path' => $product->image,
        ]);

        try {
            $uploadResponse = $this->client->uploadFile($localPath, $filename, $mimeType);

            Log::info('GHL image upload raw response', [
                'product_id' => $product->id,
                'direction'  => 'push',
                'response'   => $uploadResponse,
            ]);

            // GHL v2: { "uploadedFiles": { "filename.jpg": "https://cdn..." } }
            $cdnUrl = null;
            if (! empty($uploadResponse['uploadedFiles']) && is_array($uploadResponse['uploadedFiles'])) {
                $cdnUrl = array_values($uploadResponse['uploadedFiles'])[0] ?? null;
            }
            // Older / fallback response shapes
            $cdnUrl ??= $uploadResponse['url'] ?? $uploadResponse['fileUrl'] ?? null;

            if (! $cdnUrl) {
                throw new \RuntimeException(
                    'No CDN URL in GHL upload response: ' . json_encode($uploadResponse)
                );
            }

            $product->update(['ghl_image_url' => $cdnUrl]);

            Log::info('GHL image push succeeded', [
                'product_id' => $product->id,
                'direction'  => 'push',
                'cdn_url'    => $cdnUrl,
            ]);

            return $cdnUrl;
        } catch (\Exception $e) {
            Log::error('GHL image push failed', [
                'product_id' => $product->id,
                'direction'  => 'push',
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extensionFromMime(string $mime): ?string
    {
        return match (strtolower(trim(explode(';', $mime)[0]))) {
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif'    => 'avif',
            default         => null,
        };
    }
}
