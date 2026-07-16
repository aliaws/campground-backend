<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the new 'road' (open polyline) and 'area' (closed freeform polygon)
 * element types — both positioned via an ordered list of {x,y} points rather
 * than the box model (x/y/width/height/rotation) icon/rental markers use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->json('points')->nullable()->after('height');
            $table->float('stroke_width')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropColumn(['points', 'stroke_width']);
        });
    }
};
