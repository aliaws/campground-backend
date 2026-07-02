<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── products ──────────────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('sku');
            $table->boolean('track_product_inventory')->default(false)->after('is_taxes_enabled');
        });

        // ── product_prices ────────────────────────────────────────────────────
        Schema::table('product_prices', function (Blueprint $table) {
            // compareAtPrice from GHL (optional strike-through price)
            $table->decimal('compare_at_price', 10, 2)->nullable()->after('amount');
            // Stores local ProductVariantOption ULIDs that form this price's combo
            // e.g. [size_option_id, color_option_id] → "Small / Black"
            $table->json('variant_option_ids')->nullable()->after('currency');
        });

        // Drop stale variation_id column (FK was already removed in a prior migration)
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn('variation_id');
        });

        // ── product_variant_options ───────────────────────────────────────────
        // Prices are now stored in product_prices with variant_option_ids;
        // options only carry identity (name, ghl_option_id), not price data.
        Schema::table('product_variant_options', function (Blueprint $table) {
            $table->dropColumn(['custom_price', 'engage_price_id', 'engage_sync_status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['slug', 'track_product_inventory']);
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn(['compare_at_price', 'variant_option_ids']);
            $table->ulid('variation_id')->nullable();
        });

        Schema::table('product_variant_options', function (Blueprint $table) {
            $table->decimal('custom_price', 10, 2)->nullable();
            $table->string('engage_price_id')->nullable();
            $table->string('engage_sync_status')->default('not_synced');
        });
    }
};
