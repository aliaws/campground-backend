<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * White-label step D2: the new Settings page (/pos/settings) gets its Menu
 * Manager row, placed right after the Lead Connector Settings group so the
 * superadmin can rename, move or hide it like every other workspace item.
 */
return new class extends Migration
{
    private const KEY = '/pos/settings';

    public function up(): void
    {
        if (DB::table('engage_menu_items')->where('key', self::KEY)->exists()) {
            return;
        }

        $after = DB::table('engage_menu_items')->where('key', 'group:engage-settings')->value('sort_order');
        $position = $after !== null
            ? $after + 1
            : (int) DB::table('engage_menu_items')->whereNull('parent_key')->max('sort_order') + 1;

        // Make room: shift the top-level items that sit at or after the new spot.
        DB::table('engage_menu_items')->whereNull('parent_key')->where('sort_order', '>=', $position)->increment('sort_order');

        DB::table('engage_menu_items')->insert([
            'id' => (string) Str::ulid(),
            'key' => self::KEY,
            'parent_key' => null,
            'label' => 'Settings',
            'default_label' => 'Settings',
            'sort_order' => $position,
            'is_visible' => true,
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $position = DB::table('engage_menu_items')->where('key', self::KEY)->value('sort_order');
        if ($position === null) {
            return;
        }

        DB::table('engage_menu_items')->where('key', self::KEY)->delete();
        DB::table('engage_menu_items')->whereNull('parent_key')->where('sort_order', '>', $position)->decrement('sort_order');
    }
};
