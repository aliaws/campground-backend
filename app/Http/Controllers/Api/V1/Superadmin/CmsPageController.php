<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCmsPageRequest;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use App\Support\PublicStorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages the fixed platform CMS pages (CmsPage::SLUGS) — not a
 * general page builder, so there's no store()/destroy(); every slug
 * already exists (CmsPageSeeder, or its own migration for
 * portal-branding) and only its title/content are edited.
 *
 * These endpoints always read/write the database directly (never the
 * Redis cache) — the cache exists purely to spare the *public* site
 * repeated queries; the editor itself should always show what's really in
 * the database. Every write here calls Cache::forget(CmsPage::cacheKey())
 * for just that slug, so the very next public read repopulates the cache
 * with the fresh value instead of serving stale content.
 */
class CmsPageController extends Controller
{
    public function index(): JsonResponse
    {
        // Sorted in PHP by the fixed slug order (CmsPage::SLUGS) rather than
        // an ORDER BY, to stay Postgres/SQLite-agnostic — only 7 rows ever
        // exist, so this costs nothing.
        $pages = CmsPage::all()->sortBy(fn ($p) => array_search($p->slug, CmsPage::SLUGS, true))->values();

        return response()->json([
            'success' => true,
            'data' => CmsPageResource::collection($pages),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
        ]);
    }

    public function update(UpdateCmsPageRequest $request, string $slug): JsonResponse
    {
        if (! in_array($slug, CmsPage::SLUGS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown page.',
            ], 404);
        }

        $existing = CmsPage::query()->where('slug', $slug)->first();
        $data = $request->validated();

        // logo_url/background_image_url are managed exclusively via
        // uploadImage()/deleteImage() below, never through this form (the
        // request validation above deliberately never accepts them) — carry
        // whatever is already on the row forward so a routine text/style
        // save can never blow away an uploaded image. Checked independently
        // (not one combined condition) since home-page's hero has a
        // background image but no logo field at all.
        if (in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            $data['content']['logo_url'] = $existing?->content['logo_url'] ?? null;
        }
        if (in_array($slug, CmsPage::BACKGROUND_IMAGE_SLUGS, true)) {
            $key = $this->backgroundImageKey($slug);
            Arr::set($data['content'], $key, Arr::get($existing?->content ?? [], $key));
        }

        $page = CmsPage::query()->updateOrCreate(['slug' => $slug], $data);

        Cache::forget(CmsPage::cacheKey($slug));

        if (in_array($slug, CmsPage::MEDIA_SLUGS, true)) {
            $this->deleteUnusedMedia($existing?->content ?? [], $page->content ?? []);
        }

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
            'message' => 'Page saved.',
        ]);
    }

    /** One endpoint for both images header/footer/home-page can carry — `type` picks which content key gets the resulting URL. */
    public function uploadImage(Request $request, string $slug): JsonResponse
    {
        if (! $this->hasImages($slug)) {
            return response()->json(['success' => false, 'message' => 'This page has no manageable images.'], 404);
        }

        $request->validate([
            'type' => ['required', 'string', 'in:logo,background'],
            'image' => ['required', 'file', 'mimes:svg,png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);

        // home-page's hero has no logo_url field at all — only header/footer do.
        if ($request->input('type') === 'logo' && ! in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no logo field.'], 422);
        }
        if ($request->input('type') === 'background' && ! in_array($slug, CmsPage::BACKGROUND_IMAGE_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no background image field.'], 422);
        }

        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();
        $path = $request->file('image')->store('cms-images', 'public');
        $url = PublicStorageUrl::absolute(Storage::url($path));

        $content = $page->content;
        if ($request->input('type') === 'logo') {
            $content['logo_url'] = $url;
        } else {
            Arr::set($content, $this->backgroundImageKey($slug), $url);
        }
        $page->update(['content' => $content]);

        Cache::forget(CmsPage::cacheKey($slug));

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page->fresh()),
            'message' => 'Image uploaded.',
        ]);
    }

    public function deleteImage(Request $request, string $slug): JsonResponse
    {
        if (! $this->hasImages($slug)) {
            return response()->json(['success' => false, 'message' => 'This page has no manageable images.'], 404);
        }

        $request->validate([
            'type' => ['required', 'string', 'in:logo,background'],
        ]);

        if ($request->input('type') === 'logo' && ! in_array($slug, CmsPage::LOGO_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no logo field.'], 422);
        }
        if ($request->input('type') === 'background' && ! in_array($slug, CmsPage::BACKGROUND_IMAGE_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page has no background image field.'], 422);
        }

        $page = CmsPage::query()->where('slug', $slug)->firstOrFail();

        $content = $page->content;
        if ($request->input('type') === 'logo') {
            $content['logo_url'] = null;
        } else {
            Arr::set($content, $this->backgroundImageKey($slug), null);
        }
        $page->update(['content' => $content]);

        Cache::forget(CmsPage::cacheKey($slug));

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page->fresh()),
            'message' => 'Image removed.',
        ]);
    }

    /** Largest upload accepted per kind, in megabytes. */
    private const MEDIA_MAX_MB = ['video' => 500, 'image' => 10];

    private const MEDIA_EXTENSIONS = [
        'video' => ['mp4', 'webm', 'mov', 'm4v'],
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ];

    /**
     * Uploads a video or a thumbnail for a page that lists media
     * (CmsPage::MEDIA_SLUGS) and returns its public URL; the editor then
     * saves that URL as part of the page content.
     *
     * The file arrives in small chunks (one request each, in order) and is
     * put together here, because a video is far larger than PHP's
     * upload_max_filesize / post_max_size on most servers (2 MB / 8 MB by
     * default). Chunk `index` 0 starts a new file; the last chunk
     * (`index` = `total` - 1) checks the finished file and moves it to
     * the public disk.
     */
    public function uploadMedia(Request $request, string $slug): JsonResponse
    {
        if (! in_array($slug, CmsPage::MEDIA_SLUGS, true)) {
            return response()->json(['success' => false, 'message' => 'This page does not take media uploads.'], 404);
        }

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:video,image'],
            'upload_id' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:5000'],
            'filename' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:2048'],
        ]);

        $kind = $data['kind'];
        $index = (int) $data['index'];
        $total = (int) $data['total'];
        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));

        if (! in_array($extension, self::MEDIA_EXTENSIONS[$kind], true)) {
            return $this->mediaError('Allowed '.$kind.' files: '.implode(', ', self::MEDIA_EXTENSIONS[$kind]).'.');
        }
        if ($index >= $total) {
            return $this->mediaError('Chunk index is out of range.');
        }

        $directory = storage_path('app/cms-upload-chunks');
        File::ensureDirectoryExists($directory);
        $partPath = "{$directory}/{$data['upload_id']}.part";
        $nextPath = "{$directory}/{$data['upload_id']}.next";

        // Chunks must arrive in order: the .next file holds the index expected now.
        $expected = $index === 0 ? 0 : (is_file($nextPath) ? (int) file_get_contents($nextPath) : -1);
        if ($index !== $expected) {
            return $this->mediaError('Upload was interrupted. Please upload the file again.', 409);
        }

        file_put_contents($partPath, $request->file('chunk')->get(), $index === 0 ? 0 : FILE_APPEND);
        file_put_contents($nextPath, (string) ($index + 1));
        clearstatcache(true, $partPath);

        if (filesize($partPath) > self::MEDIA_MAX_MB[$kind] * 1024 * 1024) {
            File::delete([$partPath, $nextPath]);

            return $this->mediaError('The '.$kind.' is larger than '.self::MEDIA_MAX_MB[$kind].' MB.');
        }

        if ($index < $total - 1) {
            return response()->json(['success' => true, 'data' => ['done' => false, 'received' => $index + 1]]);
        }

        // Finished file: its real content must match the kind, whatever the name says.
        $mime = (string) File::mimeType($partPath);
        if (! str_starts_with($mime, $kind.'/')) {
            File::delete([$partPath, $nextPath]);

            return $this->mediaError('That file is not a valid '.$kind.'.');
        }

        $path = CmsPage::MEDIA_FOLDER.'/'.Str::random(40).'.'.$extension;
        $stream = fopen($partPath, 'r');
        Storage::disk('public')->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        File::delete([$partPath, $nextPath]);

        return response()->json([
            'success' => true,
            'data' => ['done' => true, 'url' => PublicStorageUrl::absolute(Storage::url($path))],
            'message' => ucfirst($kind).' uploaded.',
        ]);
    }

    private function mediaError(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /** After a save, removes uploaded media files the page no longer points at (a removed or replaced video / thumbnail). */
    private function deleteUnusedMedia(array $before, array $after): void
    {
        $unused = array_diff($this->uploadedMediaPaths($before), $this->uploadedMediaPaths($after));

        foreach ($unused as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Public-disk paths of this app's own uploaded media referenced anywhere in a page's content. */
    private function uploadedMediaPaths(array $content): array
    {
        $paths = [];
        array_walk_recursive($content, function ($value) use (&$paths) {
            if (is_string($value) && PublicStorageUrl::isOwnStoragePath($value)) {
                $path = PublicStorageUrl::diskRelativePath($value);
                if (str_starts_with($path, CmsPage::MEDIA_FOLDER.'/')) {
                    $paths[] = $path;
                }
            }
        });

        return array_unique($paths);
    }

    /** A page takes image uploads when it has a logo, a background image, or both (portal-branding has a logo only). */
    private function hasImages(string $slug): bool
    {
        return in_array($slug, CmsPage::LOGO_SLUGS, true) || in_array($slug, CmsPage::BACKGROUND_IMAGE_SLUGS, true);
    }

    /** Dot-notation path to `background_image_url` within a page's content — header/footer keep `style` at the top level, home-page nests it under `hero` since that background belongs to the hero section specifically, not the whole page. */
    private function backgroundImageKey(string $slug): string
    {
        return $slug === CmsPage::SLUG_HOME_PAGE ? 'hero.style.background_image_url' : 'style.background_image_url';
    }
}
