<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageProduct;
use App\Support\PublicStorageUrl;
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
    public function pullImageFromGhl(EngageProduct $product, string $ghlImageUrl): void
    {
        if (! $ghlImageUrl) {
            return;
        }

        // Nothing changed on GHL since last pull
        if ($product->ghl_image_url === $ghlImageUrl && $product->image) {
            Log::info('GHL image pull skipped — already in sync', [
                'product_id' => $product->id,
                'direction' => 'pull',
                'ghl_url' => $ghlImageUrl,
            ]);

            return;
        }

        Log::info('GHL image pull started', [
            'product_id' => $product->id,
            'direction' => 'pull',
            'ghl_url' => $ghlImageUrl,
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

            $filename = Str::random(40).'.'.ltrim($ext, '.');
            Storage::disk('public')->put("products/{$filename}", $response->body());

            // 2026-08-29 fix: was a hand-built `/storage/products/{filename}`
            // relative path, bypassing the disk's own APP_URL-prefixed `url`
            // config entirely — a relative reference has no meaning to Lead
            // Connector (or any other external party) that needs to fetch it.
            $absoluteUrl = PublicStorageUrl::absolute(Storage::disk('public')->url("products/{$filename}"));

            $product->update([
                'image' => $absoluteUrl,
                'ghl_image_url' => $ghlImageUrl,
            ]);

            Log::info('GHL image pull succeeded', [
                'product_id' => $product->id,
                'direction' => 'pull',
                'local_path' => $absoluteUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('GHL image pull failed', [
                'product_id' => $product->id,
                'direction' => 'pull',
                'ghl_url' => $ghlImageUrl,
                'error' => $e->getMessage(),
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
     *
     * This is the older, regular-catalog-product-style media-library upload
     * (`cdn.filesafe.space`) — still used as-is for that flow. The Service
     * (rental) update flow instead sends this app's own absolute storage URL
     * directly in the service payload and reconciles the *response* via
     * applyServiceUpdateResponseImages() below, since a real captured
     * response showed Lead Connector re-hosts a service's images on its own
     * infrastructure (a googleapis.com URL) rather than expecting a separate
     * media-library upload call for this specific resource.
     */
    public function pushImageToGhl(EngageProduct $product): ?string
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

        // Local storage path — upload the file. isOwnStoragePath() (not a
        // literal str_starts_with('/storage/')) is what makes this match
        // regardless of whether $product->image is a legacy bare relative
        // path or the now-correct absolute APP_URL-prefixed form — both
        // point at the same local file, just written differently depending
        // on when the row was created.
        if (PublicStorageUrl::isOwnStoragePath($product->image)) {
            return $this->uploadLocalImage($product);
        }

        // Full public HTTP URL (e.g. external CDN already) — use as-is
        if (str_starts_with($product->image, 'http')) {
            return $product->image;
        }

        // Anything else (bare filename, unknown scheme) — skip; never send to GHL
        Log::warning('GHL image push skipped — unrecognised image format', [
            'product_id' => $product->id,
            'image' => $product->image,
        ]);

        return null;
    }

    /**
     * After a successful `PUT calendars/services/{id}` (a Manage Service
     * create/update), Lead Connector's own response echoes back where it
     * has actually re-hosted each image it was sent — confirmed against a
     * real captured response for this exact endpoint, a
     * `storage.googleapis.com` URL, NOT the transient local URL this app
     * sent it. That Lead-Connector-returned URL is the durable, final
     * source of truth for a service image; the local copy this app stored
     * only ever existed to give Lead Connector something to fetch during
     * this one sync call.
     *
     * For every image position Lead Connector's response actually returns
     * a URL for that differs from what's currently stored:
     *  - the DB row is updated to the Lead-Connector-returned URL;
     *  - if the value it's replacing was one of this app's own local
     *    ("own storage") files, that now-superseded file is deleted from
     *    disk — but ONLY after the new URL has already been persisted
     *    successfully, never before;
     *  - a position Lead Connector's response says nothing about (or
     *    returns the exact same URL for) is left completely untouched —
     *    no fabricated deletion or replacement, satisfying "if the request
     *    fails or doesn't return a valid image URL, never touch the
     *    existing local image or DB value" at the level of each individual
     *    image, not just for an outright request failure.
     *
     * Deliberately swallows its own exceptions (logged, never re-thrown) —
     * by the time this runs, the actual GHL PUT already succeeded, and a
     * bug in this reconciliation step must never turn an otherwise-successful
     * sync into a reported failure that aborts the local save.
     */
    public function applyServiceUpdateResponseImages(EngageProduct $product, array $ghlResponse): void
    {
        try {
            $service = $ghlResponse['service'] ?? $ghlResponse;
            $images = $product->images ?? [];

            if ($images === []) {
                return;
            }

            $returnedByPosition = $this->extractReturnedImageUrls($service);

            if ($returnedByPosition === []) {
                return;
            }

            $filesToDelete = [];
            $changed = false;

            foreach ($images as &$img) {
                $position = $img['position'] ?? 0;
                $ghlUrl = $returnedByPosition[$position] ?? null;
                $currentUrl = $img['url'] ?? null;

                if (! $ghlUrl || $ghlUrl === $currentUrl) {
                    continue;
                }

                if ($currentUrl && PublicStorageUrl::isOwnStoragePath($currentUrl)) {
                    $filesToDelete[] = PublicStorageUrl::diskRelativePath($currentUrl);
                }

                $img['url'] = $ghlUrl;
                $changed = true;
            }
            unset($img);

            if (! $changed) {
                return;
            }

            $product->update(['images' => $images]);

            Log::info('GHL service image sync: replaced local image(s) with Lead Connector-returned URL(s)', [
                'product_id' => $product->id,
                'positions' => array_keys($returnedByPosition),
            ]);

            // Only delete local files after the Lead-Connector-returned URL
            // has been successfully persisted above — never before.
            $disk = Storage::disk('public');
            foreach ($filesToDelete as $relativePath) {
                try {
                    $disk->delete($relativePath);
                } catch (\Exception $e) {
                    Log::warning('GHL service image sync: failed to delete now-superseded local file', [
                        'product_id' => $product->id,
                        'path' => $relativePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('GHL service image sync: failed to reconcile response images — local image/DB value left untouched', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, string> Lead Connector-returned image URL keyed by position. */
    private function extractReturnedImageUrls(array $service): array
    {
        $byPosition = [];

        if (! empty($service['coverImage']) && is_string($service['coverImage'])) {
            $byPosition[0] = $service['coverImage'];
        }

        if (! empty($service['images']) && is_array($service['images'])) {
            foreach ($service['images'] as $i => $returnedImg) {
                if (! is_array($returnedImg) || empty($returnedImg['url']) || ! is_string($returnedImg['url'])) {
                    continue;
                }

                $position = $returnedImg['position'] ?? $i;
                $byPosition[$position] = $returnedImg['url'];
            }
        }

        return $byPosition;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function uploadLocalImage(EngageProduct $product): ?string
    {
        $disk = Storage::disk('public');
        // diskRelativePath() (not a literal substr('/storage')) correctly
        // strips the scheme+host too when $product->image is now an
        // absolute APP_URL-prefixed URL rather than a legacy bare relative
        // path — a plain substr() here would extract garbage from an
        // absolute URL's front instead of the real relative path.
        $relativePath = PublicStorageUrl::diskRelativePath($product->image);

        if (! $disk->exists($relativePath)) {
            Log::warning('GHL image push skipped — local file not found', [
                'product_id' => $product->id,
                'path' => $product->image,
            ]);

            return null;
        }

        $localPath = $disk->path($relativePath);
        $filename = basename($localPath);
        $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

        Log::info('GHL image push started', [
            'product_id' => $product->id,
            'direction' => 'push',
            'local_path' => $product->image,
        ]);

        try {
            $uploadResponse = $this->client->uploadFile($localPath, $filename, $mimeType);

            Log::info('GHL image upload raw response', [
                'product_id' => $product->id,
                'direction' => 'push',
                'response' => $uploadResponse,
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
                    'No CDN URL in GHL upload response: '.json_encode($uploadResponse)
                );
            }

            $product->update(['ghl_image_url' => $cdnUrl]);

            Log::info('GHL image push succeeded', [
                'product_id' => $product->id,
                'direction' => 'push',
                'cdn_url' => $cdnUrl,
            ]);

            return $cdnUrl;
        } catch (\Exception $e) {
            Log::error('GHL image push failed', [
                'product_id' => $product->id,
                'direction' => 'push',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extensionFromMime(string $mime): ?string
    {
        return match (strtolower(trim(explode(';', $mime)[0]))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif' => 'avif',
            default => null,
        };
    }
}
