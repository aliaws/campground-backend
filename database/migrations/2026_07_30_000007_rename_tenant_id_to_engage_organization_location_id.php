<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace business-table tenant_id (ULID) with engage_organization_location_id (UUID).
 * Drop tenant_id from product_rentals entirely.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'products',
        'bookings',
        'categories',
        'site_maps',
        'transactions',
        'site_map_elements',
        'site_map_icon_types',
    ];

    public function up(): void
    {
        $locationId = DB::table('engage_organization_locations')
            ->where('is_default', true)
            ->value('id')
            ?? DB::table('engage_organization_locations')->orderBy('created_at')->value('id');

//        if (! $locationId) {
//            throw new RuntimeException(
//                'No engage_organization_locations row found. Seed EngageOrganizationLocationSeeder before migrating.'
//            );
//        }

        // products: drop (tenant_id, sku) unique before column drop
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'tenant_id')) {
            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropUnique(['tenant_id', 'sku']);
                });
            } catch (\Throwable) {
                DB::statement('DROP INDEX IF EXISTS products_tenant_id_sku_unique');
            }
        }

        // product_rentals: drop tenant-scoped unique, then column
        if (Schema::hasTable('product_rentals') && Schema::hasColumn('product_rentals', 'tenant_id')) {
            try {
                Schema::table('product_rentals', function (Blueprint $table) {
                    $table->dropUnique(['tenant_id', 'ghl_id']);
                });
            } catch (\Throwable) {
                DB::statement('DROP INDEX IF EXISTS product_rentals_tenant_id_ghl_id_unique');
            }

            Schema::table('product_rentals', function (Blueprint $table) {
                try {
                    $table->dropIndex(['tenant_id']);
                } catch (\Throwable) {
                    // ignore
                }
                $table->dropColumn('tenant_id');
            });

            // Uniqueness per product + ghl_id going forward
            Schema::table('product_rentals', function (Blueprint $table) {
                $table->unique(['product_id', 'ghl_id'], 'product_rentals_product_id_ghl_id_unique');
            });
        }

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('engage_organization_location_id')->nullable();
            });

            DB::table($tableName)->update(['engage_organization_location_id' => $locationId]);

            //DB::statement("ALTER TABLE {$tableName} ALTER COLUMN engage_organization_location_id SET NOT NULL");

            $fkName = substr(str_replace('_', '', $tableName), 0, 18).'_eol_fk';
            Schema::table($tableName, function (Blueprint $table) use ($fkName) {
                $table->index('engage_organization_location_id');
                $table->foreign('engage_organization_location_id', $fkName)
                    ->references('id')
                    ->on('engage_organization_locations')
                    ->restrictOnDelete();
            });

            DB::statement("DROP INDEX IF EXISTS {$tableName}_tenant_id_index");

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        // Restore products SKU uniqueness per location
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'sku')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(
                    ['engage_organization_location_id', 'sku'],
                    'products_location_sku_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // Irreversible without restoring old ULID tenant values.
    }
};
