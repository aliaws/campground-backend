<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Menu Manager controls the workspace sidebar only (owner/admin/staff).
 * The superadmin's own menu (Organizations, Configurations) is defined in
 * code and always shown, so its rows are removed here: with no row, the
 * Sidebar keeps an item's default label and never hides it.
 */
return new class extends Migration
{
    private const SUPERADMIN_MENU = [
        'group:organizations' => ['Organizations', [
            '/superadmin/organizations' => 'All Organizations',
            '/superadmin/engage-identifiers' => 'Engage Identifiers',
        ]],
        'group:configurations' => ['Configurations', [
            '/superadmin/pages' => 'Site Pages',
            '/admin/countries' => 'Countries',
            '/admin/webhooks' => 'Webhooks',
            '/superadmin/menu-manager' => 'Menu Manager',
            '/superadmin/mail-settings' => 'Email Settings',
        ]],
    ];

    public function up(): void
    {
        $groups = array_keys(self::SUPERADMIN_MENU);

        DB::table('engage_menu_items')
            ->whereIn('key', $groups)
            ->orWhereIn('parent_key', $groups)
            ->delete();
    }

    public function down(): void
    {
        $now = now();
        $order = (int) DB::table('engage_menu_items')->whereNull('parent_key')->max('sort_order');

        foreach (self::SUPERADMIN_MENU as $groupKey => [$groupLabel, $children]) {
            $rows = [$this->row($groupKey, null, $groupLabel, ++$order, $now)];
            foreach (array_keys($children) as $i => $childKey) {
                $rows[] = $this->row($childKey, $groupKey, $children[$childKey], $i, $now);
            }
            DB::table('engage_menu_items')->insertOrIgnore($rows);
        }
    }

    private function row(string $key, ?string $parent, string $label, int $order, $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'key' => $key,
            'parent_key' => $parent,
            'label' => $label,
            'default_label' => $label,
            'sort_order' => $order,
            'is_visible' => true,
            'is_locked' => in_array($key, ['group:configurations', '/superadmin/menu-manager'], true),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
