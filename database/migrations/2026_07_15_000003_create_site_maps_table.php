<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per named interactive map (e.g. "Lakeside Area", "Main Grounds"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_maps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('image_url')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('tenant_id');
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_maps');
    }
};
