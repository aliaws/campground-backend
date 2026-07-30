<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-location junction tables:
 *  - users_locations (replaces users.engage_organization_location_id)
 *  - customers_locations (replaces customers.tenant_id + customers.ghl_contact_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultLocationId = DB::table('engage_organization_locations')
            ->where('is_default', true)
            ->value('id')
            ?? DB::table('engage_organization_locations')->orderBy('created_at')->value('id');

        Schema::create('users_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('engage_organization_location_id');
            $table->timestamps();

            $table->foreign('engage_organization_location_id', 'users_locations_eol_fk')
                ->references('id')
                ->on('engage_organization_locations')
                ->cascadeOnDelete();

            $table->unique(
                ['user_id', 'engage_organization_location_id'],
                'users_locations_user_location_unique'
            );
        });

        if (Schema::hasColumn('users', 'engage_organization_location_id')) {
            $rows = DB::table('users')
                ->whereNotNull('engage_organization_location_id')
                ->get(['id', 'engage_organization_location_id']);

            $now = now();
            foreach ($rows as $row) {
                DB::table('users_locations')->insertOrIgnore([
                    'user_id' => $row->id,
                    'engage_organization_location_id' => $row->engage_organization_location_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_engage_organization_location_id_foreign');
                $table->dropColumn('engage_organization_location_id');
            });
        }

        Schema::create('customers_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->uuid('engage_organization_location_id');
            $table->string('ghl_contact_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('engage_organization_location_id', 'customers_locations_eol_fk')
                ->references('id')
                ->on('engage_organization_locations')
                ->cascadeOnDelete();

            $table->unique(
                ['customer_id', 'engage_organization_location_id'],
                'customers_locations_customer_location_unique'
            );
        });

        if ($defaultLocationId && Schema::hasTable('customers')) {
            $customers = DB::table('customers')->get(['id', 'ghl_contact_id']);
            $now = now();
            foreach ($customers as $customer) {
                DB::table('customers_locations')->insertOrIgnore([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'customer_id' => $customer->id,
                    'engage_organization_location_id' => $defaultLocationId,
                    'ghl_contact_id' => $customer->ghl_contact_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Drop customer tenant + ghl_contact columns and rewrite email uniqueness to global.
        if (Schema::hasColumn('customers', 'tenant_id') || Schema::hasColumn('customers', 'ghl_contact_id')) {
            // Drop partial unique index on (tenant_id, lower(email)).
            DB::statement('DROP INDEX IF EXISTS customers_tenant_email_unique');

            Schema::table('customers', function (Blueprint $table) {
                if (Schema::hasColumn('customers', 'ghl_contact_id')) {
                    try {
                        $table->dropIndex(['ghl_contact_id']);
                    } catch (\Throwable) {
                        // ignore
                    }
                    $table->dropColumn('ghl_contact_id');
                }
                if (Schema::hasColumn('customers', 'tenant_id')) {
                    try {
                        $table->dropIndex(['tenant_id']);
                    } catch (\Throwable) {
                        // ignore
                    }
                    $table->dropColumn('tenant_id');
                }
            });

            // Global unique email among non-deleted customers.
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS customers_email_unique
                ON customers (LOWER(email))
                WHERE email IS NOT NULL AND deleted_at IS NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'engage_organization_location_id')) {
                $table->uuid('engage_organization_location_id')->nullable()->after('roles');
            }
        });

        if (Schema::hasTable('users_locations')) {
            $rows = DB::table('users_locations')->get();
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->user_id)->update([
                    'engage_organization_location_id' => $row->engage_organization_location_id,
                ]);
            }
            Schema::dropIfExists('users_locations');
        }

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'tenant_id')) {
                $table->ulid('tenant_id')->nullable()->after('address');
            }
            if (! Schema::hasColumn('customers', 'ghl_contact_id')) {
                $table->string('ghl_contact_id')->nullable()->after('address');
            }
        });

        if (Schema::hasTable('customers_locations')) {
            $rows = DB::table('customers_locations')->get();
            foreach ($rows as $row) {
                DB::table('customers')->where('id', $row->customer_id)->update([
                    'ghl_contact_id' => $row->ghl_contact_id,
                ]);
            }
            Schema::dropIfExists('customers_locations');
        }

        DB::statement('DROP INDEX IF EXISTS customers_email_unique');
    }
};
