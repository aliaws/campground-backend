<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `isVariantsEnabled` — real Lead Connector field on a service's own detail
 * response (`GET calendars/services/{id}`), confirmed via a real captured
 * payload: `true` on the base/"Regular" listing's own detail when it has
 * variants configured, `false` on a variant's own detail response for the
 * exact same underlying listing (a variant's copy of this field is NOT
 * authoritative — only the base's own detail is). Stored on the base rental
 * row only (see GhlServiceSyncService::finalizeListing()), same convention
 * as `booking_period_type`/`booking_settings` — a listing-level concept
 * carried on the base row rather than a new EngageProduct column, so
 * `resolveBaseRental()` is the one place that ever reads/writes it.
 *
 * Drives the Manage Service edit form's Inventory & Pricing tab: whether to
 * show the Variants table (this flag true) or a single inline Pricing card
 * (false) — replacing an earlier `rentals.length > 1` heuristic with the
 * real Lead Connector-authoritative value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->boolean('is_variants_enabled')->default(false)->after('pricing_rules');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn('is_variants_enabled');
        });
    }
};
