<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sidebar row for the superadmin "Email Settings" page (Configurations
 * group), so it can be renamed/reordered/hidden from the Menu Manager like
 * every other item.
 */
return new class extends Migration
{
    private const KEY = '/superadmin/mail-settings';

    private const PARENT_KEY = 'group:configurations';

    private const LABEL = 'Email Settings';

    public function up(): void
    {
        if (DB::table('engage_menu_items')->where('key', self::KEY)->exists()) {
            return;
        }

        $nextOrder = (int) DB::table('engage_menu_items')
            ->where('parent_key', self::PARENT_KEY)
            ->max('sort_order') + 1;

        DB::table('engage_menu_items')->insert([
            'id' => (string) Str::ulid(),
            'key' => self::KEY,
            'parent_key' => self::PARENT_KEY,
            'label' => self::LABEL,
            'default_label' => self::LABEL,
            'sort_order' => $nextOrder,
            'is_visible' => true,
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('engage_menu_items')->where('key', self::KEY)->delete();
    }
};
