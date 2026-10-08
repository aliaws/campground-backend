<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sidebar's "Transactions" group (Rental Transactions / Product
 * Transactions sub items) became a single "Transactions" link: the page
 * itself switches between the two ledgers with tabs. Sidebar menu rows are
 * keyed by group slug or href, so the group row is re-keyed to the new link
 * in place — keeping the label, order and visibility a superadmin already
 * set — and the two sub-item rows are removed.
 */
return new class extends Migration
{
    private const GROUP_KEY = 'group:transactions';

    private const LINK_KEY = '/pos/transactions';

    private const CHILDREN = [
        '/pos/transactions/rentals' => 'Rental Transactions',
        '/pos/transactions/products' => 'Product Transactions',
    ];

    public function up(): void
    {
        DB::table('engage_menu_items')->where('parent_key', self::GROUP_KEY)->delete();

        DB::table('engage_menu_items')
            ->where('key', self::GROUP_KEY)
            ->update(['key' => self::LINK_KEY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('engage_menu_items')
            ->where('key', self::LINK_KEY)
            ->update(['key' => self::GROUP_KEY, 'updated_at' => now()]);

        if (! DB::table('engage_menu_items')->where('key', self::GROUP_KEY)->exists()) {
            return;
        }

        $now = now();
        $order = 0;
        foreach (self::CHILDREN as $key => $label) {
            DB::table('engage_menu_items')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'key' => $key,
                'parent_key' => self::GROUP_KEY,
                'label' => $label,
                'default_label' => $label,
                'sort_order' => $order++,
                'is_visible' => true,
                'is_locked' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
