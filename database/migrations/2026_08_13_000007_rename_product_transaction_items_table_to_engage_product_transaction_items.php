<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('product_transaction_items', 'engage_product_transaction_items');
    }

    public function down(): void
    {
        Schema::rename('engage_product_transaction_items', 'product_transaction_items');
    }
};
