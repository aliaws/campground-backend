<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per custom icon image an admin has uploaded for the map builder
 * (e.g. "RV Site", "Cabin", "Dock") — reusable across every map/element for
 * that tenant. Replaces having to pick from only the built-in Lucide/emoji
 * set; the actual image assets are supplied by the client, not designed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_map_icon_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('image_url');
            $table->string('tenant_id');
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_map_icon_types');
    }
};
