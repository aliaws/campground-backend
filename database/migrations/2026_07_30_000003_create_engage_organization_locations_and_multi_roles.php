<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-location foundation + multi-role users:
 *  - engage_organization_locations (Engage/GHL location / business profile)
 *  - engage_tokens: FK to location (nullable), token_type agency|location, drop tenant_id
 *  - users: role → roles JSON; FK to location (nullable)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_organization_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_business_name')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('business_country_code', 8)->nullable();
            $table->string('business_website')->nullable();
            $table->string('business_niche')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('timezone')->nullable();
            $table->json('business_information')->nullable();
            $table->string('engage_location_id')->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Exactly one default location in the system (NULL-safe unique on pgsql).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX engage_organization_locations_one_default_idx ON engage_organization_locations ((is_default)) WHERE is_default = true');
        }

        Schema::table('engage_tokens', function (Blueprint $table) {
            $table->uuid('engage_organization_location_id')->nullable()->after('id');
            $table->string('token_type')->default('location')->after('engage_setting_id');

            $table->foreign('engage_organization_location_id')
                ->references('id')
                ->on('engage_organization_locations')
                ->nullOnDelete();
        });

        // Drop unique(tenant_id) then the column.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE engage_tokens DROP CONSTRAINT IF EXISTS engage_tokens_tenant_id_unique');
        } else {
            Schema::table('engage_tokens', function (Blueprint $table) {
                $table->dropUnique(['tenant_id']);
            });
        }

        Schema::table('engage_tokens', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('email');
            $table->uuid('engage_organization_location_id')->nullable()->after('tenant_id');

            $table->foreign('engage_organization_location_id')
                ->references('id')
                ->on('engage_organization_locations')
                ->nullOnDelete();
        });

        // Backfill roles from legacy single role column (cashier → staff).
        if (Schema::hasColumn('users', 'role')) {
            $users = DB::table('users')->select('id', 'role')->get();
            foreach ($users as $user) {
                $legacy = $user->role ?: 'staff';
                if ($legacy === 'cashier') {
                    $legacy = 'staff';
                }
                DB::table('users')->where('id', $user->id)->update([
                    'roles' => json_encode([$legacy]),
                ]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->after('email');
        });

        $users = DB::table('users')->select('id', 'roles')->get();
        foreach ($users as $user) {
            $roles = json_decode($user->roles ?? '[]', true) ?: [];
            $primary = is_array($roles) && $roles !== [] ? $roles[0] : 'staff';
            DB::table('users')->where('id', $user->id)->update(['role' => $primary]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['engage_organization_location_id']);
            $table->dropColumn(['roles', 'engage_organization_location_id']);
        });

        Schema::table('engage_tokens', function (Blueprint $table) {
            $table->ulid('tenant_id')->nullable()->after('id');
        });

        Schema::table('engage_tokens', function (Blueprint $table) {
            $table->dropForeign(['engage_organization_location_id']);
            $table->dropColumn(['engage_organization_location_id', 'token_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS engage_organization_locations_one_default_idx');
        }

        Schema::dropIfExists('engage_organization_locations');
    }
};
