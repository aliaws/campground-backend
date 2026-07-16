<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat canvas fill color (e.g. grass green) so a map can be fully drawn
 * (roads/areas/markers) with no uploaded photo required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->string('background_color')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->dropColumn('background_color');
        });
    }
};
