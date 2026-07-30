<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users no longer carry tenant_id — multi-tenancy is Engage-location based
 * (engage_organization_location_id). Business tables still use tenant_id,
 * resolved at runtime via User::resolveTenantId() → engage_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('tenant_id')->nullable()->after('email');
        });
    }
};
