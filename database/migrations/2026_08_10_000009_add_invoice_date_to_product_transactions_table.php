<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoice_date` on `product_transactions` only — user-directed, explicitly
 * scoped to the product side; `rental_transactions` is deliberately left
 * untouched (the user asked not to change anything rental-related in this
 * pass). Sourced from GHL's real invoice `issueDate` field (confirmed via a
 * live `GET invoices/` call against this tenant's real account, 2026-08-10 —
 * not guessed from docs, which required JS interaction this session
 * couldn't drive) for GHL-synced rows; set to the creation timestamp for
 * POS-created product sales (see `ProductTransactionService::create()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_transactions', function (Blueprint $table) {
            $table->timestamp('invoice_date')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_transactions', function (Blueprint $table) {
            $table->dropColumn('invoice_date');
        });
    }
};
