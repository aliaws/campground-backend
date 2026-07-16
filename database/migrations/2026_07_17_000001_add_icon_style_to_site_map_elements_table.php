<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 'filled' = the existing icon-in-a-colored-circle look; 'line' = no
 * background circle, the icon itself drawn in `color` — an outline-style
 * marker for when a solid circle is too heavy visually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->string('icon_style')->default('filled')->after('shape');
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropColumn('icon_style');
        });
    }
};
