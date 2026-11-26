<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step C2: the Lead Connector pages moved
 * from /admin/engages to /admin/leadconnector (old addresses redirect in the
 * frontend). Menu Manager rows are keyed by the link's address, so the two
 * rows are re-keyed to the new addresses. Label, order and visibility are
 * kept. The group key (group:engage-settings) is an internal name and stays.
 */
return new class extends Migration
{
    /** old key => new key */
    private const KEYS = [
        '/admin/engages' => '/admin/leadconnector',
        '/admin/engages/tokens' => '/admin/leadconnector/tokens',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $old => $new) {
            $this->rekey($old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $old => $new) {
            $this->rekey($new, $old);
        }
    }

    private function rekey(string $from, string $to): void
    {
        // Skip if a row already uses the target key (the key is unique).
        if (DB::table('engage_menu_items')->where('key', $to)->exists()) {
            return;
        }

        DB::table('engage_menu_items')->where('key', $from)->update(['key' => $to, 'updated_at' => now()]);
        DB::table('engage_menu_items')->where('parent_key', $from)->update(['parent_key' => $to, 'updated_at' => now()]);
    }
};
