<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `custom_fields.tenant_id` was missed by
 * 2026_07_30_000007_rename_tenant_id_to_engage_organization_location_id.php
 * (its own $tables list never included `custom_fields` — the feature
 * wasn't in active use on this branch at the time). Restoring the
 * `CustomField` feature during the 2026-08-12 merge with `main` surfaced
 * the gap. Same backfill-then-alter pattern as that original migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_fields') || ! Schema::hasColumn('custom_fields', 'tenant_id')) {
            return;
        }

        $locationId = DB::table('engage_organization_locations')
            ->where('is_default', true)
            ->value('id')
            ?? DB::table('engage_organization_locations')->orderBy('created_at')->value('id');

        if (! $locationId) {
            throw new RuntimeException(
                'No engage_organization_locations row found. Seed EngageOrganizationLocationSeeder before migrating.'
            );
        }

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->uuid('engage_organization_location_id')->nullable();
        });

        DB::table('custom_fields')->update(['engage_organization_location_id' => $locationId]);

        DB::statement('ALTER TABLE custom_fields ALTER COLUMN engage_organization_location_id SET NOT NULL');

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->index('engage_organization_location_id');
            $table->foreign('engage_organization_location_id', 'custom_fields_eol_fk')
                ->references('id')->on('engage_organization_locations')->restrictOnDelete();
        });

        DB::statement('DROP INDEX IF EXISTS custom_fields_tenant_id_index');

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }

    public function down(): void
    {
        // Irreversible without restoring old ULID tenant values — same
        // convention as 2026_07_30_000007's own down().
    }
};
