<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per item placed on a SiteMap — decorative ('icon') or a rental
 * marker ('rental', linked via product_rental_id). Unified table so every
 * visual property (position/size/rotation/color/opacity/layering/visibility)
 * is editable the same way regardless of element type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_map_elements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_map_id')->constrained('site_maps')->cascadeOnDelete();
            $table->string('type'); // 'icon' | 'rental'
            $table->foreignUlid('product_rental_id')->nullable()->constrained('product_rentals')->nullOnDelete();
            $table->string('icon_key')->nullable();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->float('x')->default(50); // 0-100 %, position
            $table->float('y')->default(50);
            $table->float('width')->default(6); // 0-100 %, size
            $table->float('height')->default(6);
            $table->float('rotation')->default(0); // degrees
            $table->string('color')->nullable();
            $table->float('opacity')->default(1);
            $table->integer('z_index')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('category')->nullable();
            $table->json('metadata')->nullable();
            $table->string('tenant_id');
            $table->timestamps();

            $table->index(['site_map_id', 'is_visible']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_map_elements');
    }
};
