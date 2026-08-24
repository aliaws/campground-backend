<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-editable default security deposit for a rental listing (Manage
 * Service edit form's Pricing section) — same "local override, informational/
 * display only" role as the pre-existing listing_price/service_duration_unit
 * columns on this table. Does NOT feed into BookingPriceCalculator/the live
 * GHL quote a guest actually pays — that remains entirely GHL-sourced,
 * unchanged. See CLAUDE.md's "Rental detail is not in the database" note;
 * this is the same kind of narrow, deliberate exception listing_price
 * already established, not a reversal of that principle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->decimal('security_deposit_amount', 10, 2)->nullable()->after('listing_price');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn('security_deposit_amount');
        });
    }
};
