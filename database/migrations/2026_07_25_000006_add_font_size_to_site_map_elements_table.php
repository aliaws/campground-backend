<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-element text size for a text-label icon marker (a rectangle-shaped
 * `type:'icon'` element with no icon_key, showing its `label` as plain text
 * with no background fill). Nullable — null means "use the existing default
 * size" so every element created before this feature renders identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->unsignedSmallInteger('font_size')->nullable()->after('icon_style');
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropColumn('font_size');
        });
    }
};
