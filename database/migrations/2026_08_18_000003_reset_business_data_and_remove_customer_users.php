<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time, client-directed data reset: clears all customer/booking/
     * catalog data and every customer-portal login (role: customer), while
     * leaving organizations, staff/owner/admin users, GHL tokens/settings,
     * and site maps completely untouched.
     *
     * Deliberately does NOT truncate engage_organization_locations, even
     * though it's conceptually "business data" — several tables outside
     * this reset's scope (site_maps, site_map_elements, site_map_icon_types,
     * custom_fields, customer_archives, engage_tokens, ghl_sync_logs, and
     * every *staff* engage_users_locations row, not just customer ones) are
     * structurally required by Postgres to be truncated alongside any table
     * they reference, regardless of each FK's own ON DELETE rule or whether
     * any referencing rows actually remain. Truncating organizations would
     * therefore have silently wiped staff org-links and org config this
     * reset isn't supposed to touch.
     */
    public function up(): void
    {
        // 1) Remove every customer-portal login and its own location links
        // first — a normal DELETE (not a TRUNCATE), so it only ever touches
        // role:customer rows and leaves staff/owner/admin users and their
        // org links completely alone. engage_users_locations rows cascade
        // automatically on user delete, but deleted explicitly here too for
        // clarity/robustness.
        $customerUserIds = DB::table('engage_users')
            ->whereRaw("roles::jsonb @> '[\"customer\"]'::jsonb")
            ->pluck('id');

        if ($customerUserIds->isNotEmpty()) {
            DB::table('engage_users_locations')->whereIn('user_id', $customerUserIds)->delete();
            DB::table('engage_users')->whereIn('id', $customerUserIds)->delete();
        }

        // 2) engage_users.customer_id -> engage_customers is the one FK
        // that would otherwise block truncating engage_customers below —
        // Postgres's TRUNCATE requires every table that references the
        // truncated table to also be truncated in the same statement (or
        // CASCADE), independent of the FK's ON DELETE rule and independent
        // of whether any referencing rows remain after step 1. Drop it for
        // the duration of the truncate and restore it immediately after;
        // engage_users' row data itself is never touched by this.
        //
        // The constraint's real name is "users_customer_id_foreign", not
        // Laravel's shorthand-guessed "engage_users_customer_id_foreign" —
        // it was created back when this table was still named `users`
        // (2026_07_14_000003_add_guest_fields_to_users_table.php), and the
        // 2026-08-13 table rename to engage_users never renamed the
        // constraint itself. Confirmed against pg_constraint directly.
        Schema::table('engage_users', function ($table) {
            $table->dropForeign('users_customer_id_foreign');
        });

        // site_map_elements.product_rental_id -> engage_product_rentals is
        // the same structural blocker, but for engage_product_rentals — site
        // maps are deliberately out of this reset's scope (see class doc
        // comment), so this is dropped and restored the same way rather
        // than truncating site_map_elements too.
        Schema::table('site_map_elements', function ($table) {
            $table->dropForeign('site_map_elements_product_rental_id_foreign');
        });

        // 3) Truncate the requested business/catalog data tables together in
        // one statement, so Postgres can satisfy every FK relationship
        // *between* them without CASCADE (which would otherwise also reach
        // into tables outside this list, e.g. via engage_organization_locations).
        DB::statement('TRUNCATE TABLE '.implode(', ', [
            'engage_bookings',
            'engage_categories',
            'engage_customers',
            'engage_customers_locations',
            'engage_product_categories',
            'engage_product_rental_amenities',
            'engage_product_rental_categories',
            'engage_product_rental_features',
            'engage_product_rentals',
            'engage_product_transaction_items',
            'engage_product_transactions',
            'engage_products',
            'engage_rental_transactions',
        ]));

        // Every engage_product_rentals row referenced above just vanished,
        // so any site_map_elements row that pointed at one is now dangling
        // — null it out before restoring the FK below (re-adding a FK
        // validates all existing rows, and mirrors the constraint's own
        // ON DELETE SET NULL semantics: a rental's map marker falls back to
        // an unlabeled/plain element rather than being deleted, same as an
        // icon_type_id going away).
        DB::table('site_map_elements')->whereNotNull('product_rental_id')->update(['product_rental_id' => null]);

        // 4) Restore both FKs exactly as originally defined — see
        // 2026_07_14_000003_add_guest_fields_to_users_table.php and
        // 2026_07_15_000004_create_site_map_elements_table.php respectively.
        Schema::table('engage_users', function ($table) {
            $table->foreign('customer_id')->references('id')->on('engage_customers')->nullOnDelete();
        });

        Schema::table('site_map_elements', function ($table) {
            $table->foreign('product_rental_id')->references('id')->on('engage_product_rentals')->nullOnDelete();
        });

        // 5) engage_customers_locations already has a unique(customer_id,
        // engage_organization_location_id) index
        // (engage_customers_locations_customer_location_unique) — that's the
        // actual invariant the codebase's EngageCustomer::setGhlContactIdFor()/
        // attachLocation() firstOrNew() pattern depends on (exactly one link
        // row per customer per location), so it's kept as-is, not replaced.
        // Additionally add the 3-column composite requested here if a
        // matching index doesn't already exist under any name.
        if (! $this->hasIndexOnColumns('engage_customers_locations', ['customer_id', 'engage_organization_location_id', 'ghl_contact_id'])) {
            Schema::table('engage_customers_locations', function ($table) {
                $table->unique(
                    ['customer_id', 'engage_organization_location_id', 'ghl_contact_id'],
                    'engage_customers_locations_customer_org_ghl_unique'
                );
            });
        }

        // 6) engage_users_locations already has unique(user_id,
        // engage_organization_location_id) — confirmed present as
        // engage_users_locations_user_location_unique. Nothing to add; only
        // guarded here in case an earlier environment somehow lacks it.
        if (! $this->hasIndexOnColumns('engage_users_locations', ['user_id', 'engage_organization_location_id'])) {
            Schema::table('engage_users_locations', function ($table) {
                $table->unique(['user_id', 'engage_organization_location_id'], 'engage_users_locations_user_location_unique');
            });
        }
    }

    public function down(): void
    {
        // Data removed by up() (customer users, and the truncated business/
        // catalog tables) is not restorable — this is an intentional,
        // irreversible data reset, same precedent as
        // 2026_07_10_000005_drop_legacy_rental_and_pricing_structures.php.
        // Only the newly-added index from step 5 is reversed here.
        if ($this->hasIndexNamed('engage_customers_locations', 'engage_customers_locations_customer_org_ghl_unique')) {
            Schema::table('engage_customers_locations', function ($table) {
                $table->dropUnique('engage_customers_locations_customer_org_ghl_unique');
            });
        }
    }

    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasIndexNamed(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }
};
