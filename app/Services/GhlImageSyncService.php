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

    /**
     * 2026-08-31: the real, deterministic fix — confirmed against Lead
     * Connector's own published API reference
     * (marketplace.gohighlevel.com/docs/ghl/medias/upload-media-content/)
     * that `PUT calendars/services/{id}` does NOT itself fetch-and-rehost
     * an arbitrary image URL sent in `coverImage`/`images[]` — it can just
     * echo back the exact same local URL unchanged (confirmed live from a
     * real production screenshot). The only documented way to get a real
     * Lead-Connector-hosted URL is to explicitly upload the image first via
     * `POST medias/upload-file` (see `GhlClient::uploadMediaFromUrl()`),
     * then send *that* returned URL in the service-update payload.
     *
     * Called BEFORE the outbound payload is built in
     * `GhlServiceSyncService::pushServiceUpdateToGhl()`/
     * `buildServiceUpdatePayload()`, so the very same save already carries
     * a real hosted URL — this supersedes relying on the PUT's own response
     * or a follow-up GET to happen to reflect one.
     *
     * Every image whose current URL is genuinely one of this app's own
     * local files (`PublicStorageUrl::isOwnStoragePath()`) is uploaded via
     * the hosted-URL mode (Lead Connector fetches `$url` itself — no local
     * file bytes are read or attached here); an already-external URL
     * (already hosted by Lead Connector from a prior save, or pulled in
     * from Lead Connector directly) is left completely untouched and never
     * re-uploaded, so a save with nothing new to host is a cheap no-op.
     *
     * Each image is handled independently and defensively: a failure
     * uploading one image (network blip, Lead Connector briefly
     * unreachable) is logged and that one image's local URL is left
     * exactly as-is, to retry on the next save — it never aborts the
     * other images, and never aborts the caller's own save, since the
     * real `PUT calendars/services/{id}` call doesn't actually depend on
     * this step succeeding at all.
     */
    public function ensureImagesHostedOnGhl(EngageProduct $product): void
    {
        $images = $product->images ?? [];

        if ($images === []) {
            return;
        }

        $changed = false;
        $filesToDelete = [];

        foreach ($images as &$img) {
            $url = $img['url'] ?? null;

            if (! $url || ! PublicStorageUrl::isOwnStoragePath($url)) {
                continue;
            }

            try {
                $result = $this->client->uploadMediaFromUrl($url, $img['name'] ?? $product->name);
                $filesToDelete[] = PublicStorageUrl::diskRelativePath($url);
                $img['url'] = $result['url'];
                $changed = true;

                Log::info('GHL media upload (hosted URL) succeeded for a service image', [
                    'product_id' => $product->id,
                    'position' => $img['position'] ?? null,
                    'ghl_url' => $result['url'],
                ]);
            } catch (\Exception $e) {
                Log::warning('GHL media upload (hosted URL) failed for one service image — left local, will retry on next save', [
                    'product_id' => $product->id,
                    'position' => $img['position'] ?? null,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        unset($img);

        if (! $changed) {
            return;
        }

        try {
            $product->update(['images' => $images]);
        } catch (\Exception $e) {
            // The DB write itself failed — none of the uploaded-but-not-
            // yet-persisted URLs are used, and no local file is deleted,
            // so nothing is lost; the images simply stay local and this
            // whole step retries on the next save.
            Log::error('GHL media upload: uploaded to Lead Connector but failed to persist the new URL(s) locally — local image/DB value left untouched', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Only delete local files after the Lead-Connector-hosted URL has
        // been successfully persisted above — never before.
        $disk = Storage::disk('public');
        foreach ($filesToDelete as $relativePath) {
            try {
                $disk->delete($relativePath);
            } catch (\Exception $e) {
                Log::warning('GHL media upload: failed to delete now-hosted local file', [
                    'product_id' => $product->id,
                    'path' => $relativePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

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
