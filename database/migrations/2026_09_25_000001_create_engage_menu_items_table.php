<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform-global (not per-organization) staff sidebar configuration: the
 * superadmin controls label, visibility and ordering of every top-level
 * menu item and sub menu item, applied to every workspace. Rows are keyed
 * by a stable `key` (a group slug like "group:booking", or a link's href)
 * that the frontend Sidebar matches against — href/icon/permission gating
 * stay in code; only label/visibility/order live here.
 *
 * Seeded below from the Sidebar's existing hardcoded menu so nothing
 * changes visually until a superadmin edits something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_menu_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('parent_key')->nullable()->index();
            $table->string('label');
            $table->string('default_label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // Locked rows can never be hidden (they're how the superadmin
            // reaches this very screen).
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        $menu = [
            ['/pos/dashboard', 'Dashboard'],
            ['/pos/products/sell', 'POS'],
            ['group:organizations', 'Organizations', [
                ['/superadmin/organizations', 'All Organizations'],
                ['/superadmin/engage-identifiers', 'Engage Identifiers'],
            ]],
            ['group:booking', 'Booking', [
                ['/pos/bookings/new', 'New Booking'],
                ['/pos/bookings', 'Bookings'],
                ['/pos/bookings/check-in', 'Check-in'],
                ['/pos/reports', 'Report'],
            ]],
            ['/pos/campsite-map', 'Campsite Map'],
            ['group:customers', 'Customers', [
                ['/pos/customers', 'Customers'],
                ['/pos/customers/archive', 'Customer Archive'],
            ]],
            ['group:transactions', 'Transactions', [
                ['/pos/transactions/rentals', 'Rental Transactions'],
                ['/pos/transactions/products', 'Product Transactions'],
            ]],
            ['group:products', 'Products', [
                ['/pos/products/orders', 'Product Orders'],
                ['/admin/products', 'Manage Products'],
                ['/admin/categories', 'Categories'],
            ]],
            ['group:rental-services', 'Rental Services', [
                ['/pos/services/manage', 'Manage Service'],
                ['/pos/services/categories', 'Service Categories'],
                ['/pos/services/amenities', 'Amenities'],
                ['/pos/services/features', 'Features'],
            ]],
            ['/pos/staff', 'Staff'],
            ['group:engage-settings', 'Engage Settings', [
                ['/admin/engages', 'Refresh Token & Sync'],
                ['/admin/engages/tokens', 'Engage Tokens'],
            ]],
            ['group:configurations', 'Configurations', [
                ['/superadmin/pages', 'Site Pages'],
                ['/admin/countries', 'Countries'],
                ['/admin/webhooks', 'Webhooks'],
                ['/superadmin/menu-manager', 'Menu Manager'],
            ]],
        ];

        $locked = ['group:configurations', '/superadmin/menu-manager'];
        $now = now();
        $rows = [];

        foreach ($menu as $i => $item) {
            [$key, $label] = $item;
            $rows[] = $this->row($key, null, $label, $i, in_array($key, $locked, true), $now);
            foreach ($item[2] ?? [] as $j => [$childKey, $childLabel]) {
                $rows[] = $this->row($childKey, $key, $childLabel, $j, in_array($childKey, $locked, true), $now);
            }
        }

        DB::table('engage_menu_items')->insert($rows);
    }

    private function row(string $key, ?string $parent, string $label, int $order, bool $locked, $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'key' => $key,
            'parent_key' => $parent,
            'label' => $label,
            'default_label' => $label,
            'sort_order' => $order,
            'is_visible' => true,
            'is_locked' => $locked,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_menu_items');
    }
};
