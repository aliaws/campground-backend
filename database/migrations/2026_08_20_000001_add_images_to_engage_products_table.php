<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the full images array a GHL rental service can carry
 * (calendars/services/{id} -> images: [{_id, url, name, position}, ...]) —
 * previously only the single "cover" URL was persisted (products.image),
 * discarding every additional image GHL returns. `image` itself is
 * untouched and keeps being the default/position-0 image, used everywhere
 * a single image is required; `images` is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('engage_products', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
