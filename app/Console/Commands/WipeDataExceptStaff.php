<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently deletes every row from every application-data table except
 * `users` rows with role admin/staff. Deliberately never touches:
 *   - `engage_settings` (GHL OAuth tokens/location id — wiping this breaks
 *     the GHL integration until someone re-authorizes from scratch)
 *   - `countries` (static reference data)
 *   - framework tables (`migrations`, `cache*`, `jobs`, `sessions`, etc.)
 *
 * Deletion order was derived from the real live FK graph (every FK in this
 * schema is CASCADE or SET NULL, none RESTRICT — see the constraint list in
 * information_schema), not guessed: children are deleted before parents so
 * the command works even if a future migration ever tightens a rule to
 * RESTRICT. `personal_access_tokens` for deleted users are cleared first
 * since Sanctum's tokenable relation is a polymorphic pair with no real DB
 * FK constraint to rely on for cascade.
 */
class WipeDataExceptStaff extends Command
{
    protected $signature = 'db:wipe-except-staff
        {--force : Skip the interactive confirmation prompt}
        {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Delete all application data except users with role admin/staff (and engage_settings/countries, which are never touched).';

    /** Child-to-parent order, per the live FK graph. */
    private const TABLES_IN_DELETE_ORDER = [
        'engage_product_transaction_items',
        'site_map_elements',
        'engage_product_categories',
        'product_rental_amenities',
        'product_rental_features',
        'engage_rental_transactions',
        'engage_product_transactions',
        'engage_bookings',
        'engage_product_rentals',
        'engage_products',
        'site_maps',
        'site_map_icon_types',
        'amenities',
        'engage_categories',
        'features',
        'webhook_logs',
        'ghl_sync_logs',
        'custom_fields',
        'engage_customers',
    ];

    public function handle(): int
    {
        $keptUserIds = User::whereIn('role', ['admin', 'staff'])->pluck('id');
        $usersToDelete = User::whereNotIn('role', ['admin', 'staff'])->count();

        $counts = [];
        foreach (self::TABLES_IN_DELETE_ORDER as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $tokenCount = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereNotIn('tokenable_id', $keptUserIds)
            ->count();

        $this->table(['Table', 'Rows to delete'], collect($counts)
            ->merge(['personal_access_tokens (non-staff users\' tokens)' => $tokenCount])
            ->merge(['users (role NOT IN admin,staff)' => $usersToDelete])
            ->map(fn ($count, $table) => [$table, $count])
            ->values()
            ->all());

        $this->info('Kept untouched: engage_settings, countries, users with role admin/staff, and all framework tables.');

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $answer = $this->ask('This permanently deletes the data shown above and cannot be undone. Type DELETE to confirm');
            if ($answer !== 'DELETE') {
                $this->warn('Aborted — nothing was deleted.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($keptUserIds) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereNotIn('tokenable_id', $keptUserIds)
                ->delete();

            foreach (self::TABLES_IN_DELETE_ORDER as $table) {
                DB::table($table)->delete();
            }

            User::whereNotIn('role', ['admin', 'staff'])->delete();
        });

        Log::warning('db:wipe-except-staff executed', ['counts' => $counts, 'users_deleted' => $usersToDelete]);

        $this->info('Done. All data deleted except engage_settings, countries, and admin/staff users.');

        return self::SUCCESS;
    }
}
