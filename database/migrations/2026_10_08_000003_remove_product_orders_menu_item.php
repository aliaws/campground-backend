<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Product Orders page was removed: Transactions > Product Transactions
 * is the one list of product sales now. Its sidebar menu row goes with it.
 */
return new class extends Migration
{
    private const KEY = '/pos/products/orders';

    public function up(): void
    {
        DB::table('engage_menu_items')->where('key', self::KEY)->delete();
    }

    public function down(): void
    {
        if (! DB::table('engage_menu_items')->where('key', 'group:products')->exists()) {
            return;
        }

        DB::table('engage_menu_items')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'key' => self::KEY,
            'parent_key' => 'group:products',
            'label' => 'Product Orders',
            'default_label' => 'Product Orders',
            'sort_order' => 0,
            'is_visible' => true,
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
