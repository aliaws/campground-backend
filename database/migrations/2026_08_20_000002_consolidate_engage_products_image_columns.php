<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidates engage_products' two image-storage columns into one:
 * `images` (JSON array of {url, name, position, _id}) becomes the sole
 * source of truth, and the legacy single-string `image` column is dropped.
 * `App\Models\EngageProduct::image()` becomes a computed accessor/mutator
 * over `images` (position:0 entry) instead of a real column — every
 * existing ->image read and ['image' => ...] write across the app keeps
 * working completely unchanged, they're just backed by `images` now.
 *
 * `ghl_image_url` is a genuinely different concept (a GHL-upload cache
 * marker used by GhlImageSyncService/GhlProductSyncService to avoid
 * re-uploading an unchanged local file) and is deliberately left alone —
 * it was never "a second image," just sync bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill images for any row that still only has the legacy
        // `image` column populated (i.e. hasn't been through the
        // "Support Multiple Service Images" pull yet) — must happen before
        // `image` is dropped below, or this data is gone for good. The
        // empty/already-populated check happens in PHP, not SQL — Postgres'
        // `json` column type (as opposed to `jsonb`) has no `=` operator, so
        // a `where('images', '[]')` clause 500s; decoding in the loop is
        // also portable across the sqlite dev / Postgres prod split this
        // app supports.
        DB::table('engage_products')
            ->whereNotNull('image')
            ->orderBy('id')
            ->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $existingImages = $product->images ? json_decode((string) $product->images, true) : [];

                    if (! empty($existingImages)) {
                        continue;
                    }

                    DB::table('engage_products')->where('id', $product->id)->update([
                        'images' => json_encode([[
                            'url' => $product->image,
                            'name' => $product->name,
                            'position' => 0,
                            '_id' => null,
                        ]]),
                    ]);
                }
            });

        Schema::table('engage_products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    public function down(): void
    {
        Schema::table('engage_products', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
        });

        // Best-effort backfill from images[0].url — every row this
        // migration itself converted round-trips exactly; a row whose
        // `images` was edited/repulled since `up()` ran only loses the
        // *convenience* of the flat column, not any real data (it's still
        // fully present in `images`).
        DB::table('engage_products')
            ->whereNotNull('images')
            ->orderBy('id')
            ->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $images = json_decode((string) $product->images, true) ?: [];
                    $first = collect($images)->firstWhere('position', 0) ?? ($images[0] ?? null);

                    if ($first['url'] ?? null) {
                        DB::table('engage_products')->where('id', $product->id)->update([
                            'image' => $first['url'],
                        ]);
                    }
                }
            });
    }
};
