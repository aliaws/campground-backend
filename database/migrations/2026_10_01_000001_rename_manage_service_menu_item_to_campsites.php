<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The staff "Manage Service" page moved from /pos/services/manage to
 * /pos/campsites/manage and is now called "Manage Campsites". Sidebar menu
 * rows are keyed by href, so the existing row is re-keyed in place — this
 * keeps whatever order/visibility a superadmin already set for it. A label
 * the superadmin customized is left alone; only an untouched default label
 * is renamed.
 */
return new class extends Migration
{
    private const OLD_KEY = '/pos/services/manage';

    private const NEW_KEY = '/pos/campsites/manage';

    private const OLD_LABEL = 'Manage Service';

    private const NEW_LABEL = 'Manage Campsites';

    public function up(): void
    {
        $this->rekey(self::OLD_KEY, self::NEW_KEY, self::OLD_LABEL, self::NEW_LABEL);
    }

    public function down(): void
    {
        $this->rekey(self::NEW_KEY, self::OLD_KEY, self::NEW_LABEL, self::OLD_LABEL);
    }

    private function rekey(string $fromKey, string $toKey, string $fromLabel, string $toLabel): void
    {
        DB::table('engage_menu_items')
            ->where('key', $fromKey)
            ->where('label', $fromLabel)
            ->update(['label' => $toLabel]);

        DB::table('engage_menu_items')
            ->where('key', $fromKey)
            ->update(['key' => $toKey, 'default_label' => $toLabel, 'updated_at' => now()]);
    }
};
