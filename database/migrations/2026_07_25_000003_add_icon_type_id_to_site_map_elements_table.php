<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When set, an element renders the custom uploaded image from
 * site_map_icon_types instead of resolving icon_key against the built-in
 * Lucide/emoji registries. nullOnDelete: deleting an icon type just falls
 * the marker back to the default pin — it never deletes the element itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->foreignUlid('icon_type_id')->nullable()->after('icon_key')->constrained('site_map_icon_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_map_elements', function (Blueprint $table) {
            $table->dropForeign(['icon_type_id']);
            $table->dropColumn('icon_type_id');
        });
    }
};
