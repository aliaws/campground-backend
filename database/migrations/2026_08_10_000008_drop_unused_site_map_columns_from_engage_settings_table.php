<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `engage_settings.site_map_image_url`/`site_map_layout` — found during a
 * 2026-08-10 unused-column audit (backend `$fillable`/casts + a full grep of
 * `app/`, `routes/`, and the entire frontend). Both columns are:
 * - **Not in `EngageSetting::$fillable`** — the model doesn't even
 *   acknowledge them.
 * - **Zero code references anywhere**, backend or frontend.
 * - Recorded in the `migrations` ledger as added by
 *   `2026_07_16_000001_add_site_map_image_url_to_engage_settings` /
 *   `..._000002_add_site_map_layout_to_engage_settings`, but neither
 *   migration file exists in the repo anymore — same orphaned pattern as
 *   `business_settings` (dropped earlier the same day): the columns
 *   outlived the code and even the migration that created them.
 *
 * `site_map_image_url` was null (nothing lost). `site_map_layout` held one
 * real row of leftover JSON — a freehand-drawing map layout (roads, a lake,
 * a building label) from what must have been the very first prototype of
 * the Property Map Builder, predating even the `site_maps`/
 * `site_map_elements` tables it was later rebuilt on. That whole
 * freehand-drawing direction was already deliberately, irreversibly removed
 * from the *current* map builder in migration
 * `2026_07_25_000001_drop_drawing_tool_columns_from_site_maps.php` (see
 * "Property Map Builder" under Key Business Logic) — this is orphaned data
 * from an even earlier iteration that predates that removal, with no code
 * path anywhere to ever read it. Preserved here for the record:
 *
 * {"background":"#d4edda","elements":[
 *   {"id":"water_3mkrd560","type":"water","color":"#4fc3f7","label":"Lake","points":[...13 points...]},
 *   {"id":"road_juos5mo0","type":"road","color":"#f5f5f5","strokeWidth":2.2,"points":[...2 points...]},
 *   ...(14 more "road" segments, same shape)...,
 *   {"id":"building_8q5fpl3h","type":"building","x":55.35,"y":24.89,"width":8,"height":5,"color":"#a1887f","label":"Office"}
 * ]}
 * (full raw JSON also visible in this session's terminal history if ever needed byte-for-byte)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded, not unconditional: the migrations that originally added
        // these columns (`2026_07_16_000001`/`..._000002_add_site_map_*_to_
        // engage_settings`) no longer exist in this repo, so a *fresh*
        // database (a clean `migrate` from scratch, or any new deployment)
        // never creates these columns in the first place — only the one
        // real dev database, which accumulated them from that now-deleted
        // history, actually has them. Without this guard, `dropColumn()`
        // throws on a column that was never there.
        Schema::table('engage_settings', function (Blueprint $table) {
            if (Schema::hasColumn('engage_settings', 'site_map_image_url')) {
                $table->dropColumn('site_map_image_url');
            }
            if (Schema::hasColumn('engage_settings', 'site_map_layout')) {
                $table->dropColumn('site_map_layout');
            }
        });
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('engage_settings', 'site_map_image_url')) {
                $table->string('site_map_image_url')->nullable();
            }
            if (! Schema::hasColumn('engage_settings', 'site_map_layout')) {
                $table->json('site_map_layout')->nullable();
            }
        });
    }
};
