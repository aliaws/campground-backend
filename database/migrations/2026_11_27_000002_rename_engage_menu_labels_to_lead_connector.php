<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step C1: "Engage" -> "Lead Connector" in
 * the sidebar. Menu Manager rows override the labels written in code, so the
 * two workspace rows are renamed here too. A label the superadmin changed
 * (no longer equal to its default) is left as it is; the default label is
 * always updated so the Menu Manager shows the new default.
 *
 * Only labels change here; the keys (/admin/engages, ...) stay until the page
 * addresses are renamed.
 */
return new class extends Migration
{
    /** key => [old default, new default] */
    private const LABELS = [
        'group:engage-settings' => ['Engage Settings', 'Lead Connector Settings'],
        '/admin/engages/tokens' => ['Engage Tokens', 'Lead Connector Tokens'],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            $this->relabel($key, $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            $this->relabel($key, $new, $old);
        }
    }

    private function relabel(string $key, string $from, string $to): void
    {
        DB::table('engage_menu_items')->where('key', $key)->where('label', $from)->update(['label' => $to]);
        DB::table('engage_menu_items')->where('key', $key)->update(['default_label' => $to, 'updated_at' => now()]);
    }
};
