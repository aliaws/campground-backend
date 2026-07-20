<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the freehand road/area drawing tools in favor of an upload-a-photo
 * + drag-and-drop-labeled-icons builder — customers already have a map of
 * their property and just mark sites on it, they don't draw one from
 * scratch. `points` was a road/area element's only shape data, so those rows
 * are deleted outright (not just hidden) before the column is dropped;
 * keeping empty husks would be worse than removing them cleanly. No
 * production data is at stake — this feature was built and iterated on
 * entirely within one development cycle, no customer has used the drawing
 * tools. Irreversible without a DB backup, same precedent as
 * 2026_07_10_000005_drop_legacy_rental_and_pricing_structures.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_map_elements')->whereIn('type', ['road', 'area'])->delete();

        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropColumn(['points', 'stroke_width', 'metadata']);
        });

        Schema::table('site_maps', function (Blueprint $table) {
            $table->dropColumn('background_color');
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->json('points')->nullable()->after('height');
            $table->float('stroke_width')->nullable()->after('color');
            $table->json('metadata')->nullable();
        });

        Schema::table('site_maps', function (Blueprint $table) {
            $table->string('background_color')->nullable()->after('image_url');
        });

        // Deleted road/area rows are not recoverable — restore from a DB backup if needed.
    }
};
