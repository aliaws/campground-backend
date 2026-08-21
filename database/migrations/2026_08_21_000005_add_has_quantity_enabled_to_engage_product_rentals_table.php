<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `hasQuantityEnabled` — the real Lead Connector field (confirmed present on
 * both the services-list API and the service-detail API, alongside
 * `quantity`/`maxQuantity`) that actually decides whether a service/variant
 * tracks stock at all. This supersedes the previous design where the
 * Manage Service edit form's "Inventory" switch was a purely local,
 * unpersisted frontend toggle with no real backing data — that toggle is
 * removed entirely in this same change; `has_quantity_enabled` is now the
 * single source of truth, mirrored 1:1 from the API field of the same name.
 *
 * Per-row, same as `quantity`/`pricing_rules` (every service/variant is its
 * own full Lead Connector service record with its own copy of this field,
 * unlike `is_variants_enabled`, which is only ever authoritative on the
 * base's own detail response) — see GhlServiceSyncService::upsertRentalRow().
 *
 * No other inventory/quantity-related boolean exists on this table today —
 * confirmed by inspecting the live schema before adding this column, so
 * there is nothing else to drop here. `quantity` itself is unchanged and
 * still stores the actual stock count; its previous implicit "null means
 * not tracked" reading is superseded by this explicit flag as the single
 * source of truth for whether inventory is tracked at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->boolean('has_quantity_enabled')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn('has_quantity_enabled');
        });
    }
};
