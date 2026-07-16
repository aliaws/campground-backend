<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 'rectangle' lets a rental marker render as a colored, numbered site block
 * (KOA-style site map) instead of an icon-in-a-circle; 'circle' keeps the
 * existing icon marker look for decorative elements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->string('shape')->default('circle')->after('icon_key'); // 'circle' | 'rectangle'
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropColumn('shape');
        });
    }
};
