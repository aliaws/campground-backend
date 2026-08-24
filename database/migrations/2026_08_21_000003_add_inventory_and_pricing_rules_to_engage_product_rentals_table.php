<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manage Service's "Inventory & Pricing" tab (2026-08-21) — per-variant Stock
 * and Advanced Pricing (discount rules), mirroring Lead Connector's own
 * "Inventory & pricing" screen: each rental row (base listing included, as
 * its own "Regular" variant) carries its own quantity + discount-rule set,
 * confirmed via real captured Lead Connector service-detail payloads.
 *
 * `quantity` — nullable integer, null = unlimited stock, matching
 * GhlServiceDetail::quantity()'s own established "null = unlimited" doc
 * convention. Deliberately a *new*, per-variant column, not a reuse of
 * `engage_products.quantity` (a pre-existing, listing-wide field already
 * wired end-to-end for the single-price/no-variants case) — the two are
 * independent so nothing about the pre-existing listing-level Quantity field
 * changes behavior.
 *
 * `pricing_rules` — nullable json, the raw `pricingRule.rules[]` array from
 * Lead Connector's service detail, stored verbatim (same "GHL's own raw
 * field names, no renaming" convention already used for `booking_settings`)
 * — covers all four Advanced Pricing categories (date_range/seasonal,
 * day_of_week, duration_discount, quantity_discount) without needing to know
 * or enumerate each category's own internal field shape up front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->integer('quantity')->nullable()->after('security_deposit_amount');
            $table->json('pricing_rules')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'pricing_rules']);
        });
    }
};
