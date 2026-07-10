<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('engage_product_id', 'ghl_product_id');
        });

        Schema::table('products', function (Blueprint $table) {
            // Base listing quantity snapshot from GHL; null = unlimited.
            $table->integer('quantity')->nullable();
            // Starting ("from") price for the listing page = cheapest variant base price.
            $table->decimal('price', 10, 2)->nullable();
            // Default product_rentals row for this listing. Indexed but no DB FK —
            // circular reference with product_rentals.product_id.
            $table->ulid('product_rental_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'price', 'product_rental_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('ghl_product_id', 'engage_product_id');
        });
    }
};
