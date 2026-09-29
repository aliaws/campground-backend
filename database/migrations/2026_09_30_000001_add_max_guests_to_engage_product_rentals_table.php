<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many guests a rental can take — local-only (Lead Connector has no
 * capacity field), edited in Manage Service, and used by the homepage's
 * Guests filter. NULL = any number of guests, so every existing listing
 * stays visible until a limit is set. Written to every rental row of a
 * listing (base + variants) together, same as service_category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_guests')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn('max_guests');
        });
    }
};
