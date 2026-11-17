<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Lead Connector order with several campsites has one invoice and one
 * local booking (with its own campsite transaction) per campsite, so the
 * invoice id can no longer be unique per organization. Kept as a plain index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_rental_transactions', function (Blueprint $table) {
            $table->dropUnique('engage_rental_transactions_eol_ghl_invoice_id_unique');
            $table->index(['engage_organization_location_id', 'ghl_invoice_id'], 'engage_rental_transactions_eol_ghl_invoice_id_index');
        });
    }

    public function down(): void
    {
        // Fails if an invoice is now shared by several campsite transactions.
        Schema::table('engage_rental_transactions', function (Blueprint $table) {
            $table->dropIndex('engage_rental_transactions_eol_ghl_invoice_id_index');
            $table->unique(['engage_organization_location_id', 'ghl_invoice_id'], 'engage_rental_transactions_eol_ghl_invoice_id_unique');
        });
    }
};
