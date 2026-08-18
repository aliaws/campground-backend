<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `service_categories`, `customer_archives`, `ghl_sync_logs`,
 * `rental_transactions`, `product_transactions` were each created by their
 * own migration using the current `engage_organization_location_id` (uuid)
 * shape — but on a database that had already run those migrations against
 * an *older* copy of the same files (from before the 2026-08-12 merge
 * renamed tenant_id -> engage_organization_location_id on this branch),
 * the physical tables still carry the old `tenant_id` (ulid) column. The
 * migrations table has no way to detect this — it only tracks "did this
 * filename run," not "does the file's current content match what actually
 * ran" — so `migrate` silently considered these five done while the code
 * queries a column that was never renamed on disk. Same backfill-then-alter
 * pattern as 2026_07_30_000007 / 2026_08_12_000004's own tenant_id fixes.
 */
return new class extends Migration
{
    /** @var array<string, array{unique: array<int, string>|null, index: array<int, string>}> */
    private array $tables = [
        'service_categories' => [
            'unique' => ['engage_organization_location_id', 'ghl_category_id'],
            'old_unique_index' => 'service_categories_tenant_id_ghl_category_id_unique',
            'old_unique_is_constraint' => true,
            'old_plain_index' => 'service_categories_tenant_id_index',
            'fk' => 'service_categories_eol_fk',
        ],
        'customer_archives' => [
            'unique' => null,
            // Created via a raw `CREATE UNIQUE INDEX` (2026_08_12_000003,
            // driver-aware partial index), not Laravel's $table->unique() —
            // Postgres treats it as a bare index, not a table constraint.
            'old_unique_index' => 'customer_archives_tenant_email_unique',
            'old_unique_is_constraint' => false,
            'old_plain_index' => 'customer_archives_tenant_id_index',
            'fk' => 'customer_archives_eol_fk',
        ],
        'ghl_sync_logs' => [
            'unique' => null,
            'old_unique_index' => null,
            'old_unique_is_constraint' => false,
            'old_plain_index' => 'ghl_sync_logs_tenant_id_index',
            'old_composite_index' => 'ghl_sync_logs_tenant_id_created_at_index',
            'fk' => 'ghl_sync_logs_eol_fk',
        ],
        'rental_transactions' => [
            'unique' => ['engage_organization_location_id', 'ghl_invoice_id'],
            'old_unique_index' => 'rental_transactions_tenant_id_ghl_invoice_id_unique',
            'old_unique_is_constraint' => true,
            'old_plain_index' => 'rental_transactions_tenant_id_index',
            'fk' => 'rental_transactions_eol_fk',
        ],
        'product_transactions' => [
            'unique' => ['engage_organization_location_id', 'ghl_invoice_id'],
            'old_unique_index' => 'product_transactions_tenant_id_ghl_invoice_id_unique',
            'old_unique_is_constraint' => true,
            'old_plain_index' => 'product_transactions_tenant_id_index',
            'fk' => 'product_transactions_eol_fk',
        ],
    ];

    public function up(): void
    {
        $locationId = DB::table('engage_organization_locations')
            ->where('is_default', true)
            ->value('id')
            ?? DB::table('engage_organization_locations')->orderBy('created_at')->value('id');

        foreach ($this->tables as $tableName => $spec) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            if (! $locationId) {
                throw new RuntimeException(
                    'No engage_organization_locations row found. Seed EngageOrganizationLocationSeeder before migrating.'
                );
            }

            // Drop old tenant_id-keyed indexes/uniques first — Postgres won't
            // let the column be dropped while they still reference it.
            // The *_unique ones were created via $table->unique(...), which
            // Postgres materializes as a real constraint, not a bare index —
            // DROP INDEX fails on those with "dependent objects still
            // exist"; they need ALTER TABLE ... DROP CONSTRAINT instead. The
            // plain (non-unique) ones really are bare indexes.
            if (! empty($spec['old_unique_index'])) {
                if ($spec['old_unique_is_constraint']) {
                    DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$spec['old_unique_index']}");
                } else {
                    DB::statement("DROP INDEX IF EXISTS {$spec['old_unique_index']}");
                }
            }
            if (! empty($spec['old_composite_index'])) {
                DB::statement("DROP INDEX IF EXISTS {$spec['old_composite_index']}");
            }
            if (! empty($spec['old_plain_index'])) {
                DB::statement("DROP INDEX IF EXISTS {$spec['old_plain_index']}");
            }

            if (! Schema::hasColumn($tableName, 'engage_organization_location_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->uuid('engage_organization_location_id')->nullable();
                });
            }

            DB::table($tableName)->update(['engage_organization_location_id' => $locationId]);

            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN engage_organization_location_id SET NOT NULL");

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $spec) {
                $table->index('engage_organization_location_id');

                if ($spec['unique']) {
                    $table->unique($spec['unique']);
                }

                $table->foreign('engage_organization_location_id', $spec['fk'])
                    ->references('id')->on('engage_organization_locations')->restrictOnDelete();
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }

    public function down(): void
    {
        // Irreversible without restoring old ULID tenant values — same
        // convention as 2026_07_30_000007's/2026_08_12_000004's own down().
    }
};
