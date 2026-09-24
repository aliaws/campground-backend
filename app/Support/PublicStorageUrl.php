<?php

namespace App\Support;

/**
 * Normalizes a `public` disk storage reference into a real, absolute,
 * publicly-fetchable URL built from the backend's own configured
 * `APP_URL` — and, in reverse, extracts the disk-relative path back out of
 * either form.
 *
 * 2026-08-29 fix: product/service images were being stored as a bare
 * `/storage/products/{file}.jpg` path in some code paths (most notably
 * GhlImageSyncService::pullImageFromGhl(), which hand-built the path
 * instead of using Storage::url()) — a relative reference has no meaning
 * to an external third party like Lead Connector, which needs a real
 * absolute URL to fetch the image from anywhere. `config/filesystems.php`'s
 * `public` disk already has its `url` correctly wired to
 * `APP_URL.'/storage'`, so `Storage::url()`/`Storage::disk('public')->url()`
 * already produce an absolute URL when used correctly — `absolute()` here
 * is deliberately still applied on top of every write site as an explicit,
 * defense-in-depth guarantee (never trusting the filesystem config alone),
 * and — critically — is also applied at every point a stored value is read
 * back for use in a Lead Connector payload, so an already-existing row
 * that was stored as a bare relative path before this fix is corrected on
 * the fly rather than requiring every caller to assume the DB value is
 * already well-formed.
 */
class PublicStorageUrl
{
    /**
     * Returns an absolute, `APP_URL`-prefixed URL. An already-absolute
     * value (`http://`/`https://`, whether our own domain or a genuinely
     * external one, e.g. Lead Connector's own CDN) is returned completely
     * unchanged — never re-prefixed, which is what would otherwise produce
     * a broken `https://example.com/https://example.com/storage/...`
     * double-prefixed URL.
     */
    public static function absolute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($value, '/');
    }

    /**
     * True when $value — relative or absolute — points at a path under
     * this application's own `/storage/` public-disk mount, as opposed to
     * a genuinely external URL (Lead Connector's own CDN, or any other
     * third-party host) that must never be treated as a local file to
     * re-upload or delete. Uses `parse_url()` so it works identically
     * whether $value is a bare relative path or a full absolute URL — the
     * same idiom already established elsewhere in this codebase for
     * avatar URLs (see StaffAccountService/CustomerAccountService).
     */
    public static function isOwnStoragePath(?string $value): bool
    {
        if (! $value) {
            return false;
        }

        $path = parse_url($value, PHP_URL_PATH) ?? $value;

        return str_starts_with($path, '/storage/');
    }

    /**
     * Extracts the disk-relative path (e.g. `products/xyz.jpg`) from either
     * a relative `/storage/products/xyz.jpg` or an absolute
     * `https://host/storage/products/xyz.jpg` value.
     */
    public static function diskRelativePath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH) ?? $value;

        return ltrim(str_replace('/storage/', '', $path), '/');
    }
}
