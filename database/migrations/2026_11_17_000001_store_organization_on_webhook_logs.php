<?php

use App\Models\WebhookLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_logs.tenant_id was created as a ULID (char 26), which can't hold an
 * organization id (a UUID), so it was never filled. Make it a UUID linked to
 * engage_organization_locations, then fill it for existing rows from the Lead
 * Connector location id in each payload. Any old value is dropped: a 26-character
 * value can't be an organization id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE webhook_logs ALTER COLUMN tenant_id TYPE uuid USING NULL');
        } else {
            DB::table('webhook_logs')->update(['tenant_id' => null]);
            Schema::table('webhook_logs', function (Blueprint $table) {
                $table->uuid('tenant_id')->nullable()->change();
            });
        }

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('engage_organization_locations')->nullOnDelete();
            $table->index('source');
            $table->index('created_at');
        });

        $organizations = DB::table('engage_organization_locations')
            ->whereNotNull('engage_location_id')
            ->pluck('id', 'engage_location_id');

        DB::table('webhook_logs')->select(['id', 'payload'])->orderBy('id')->chunkById(500, function ($rows) use ($organizations) {
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $ghlLocationId = is_array($payload) ? WebhookLog::ghlLocationIdFromPayload($payload) : null;
                $organizationId = $ghlLocationId ? ($organizations[$ghlLocationId] ?? null) : null;

                if ($organizationId) {
                    DB::table('webhook_logs')->where('id', $row->id)->update(['tenant_id' => $organizationId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE webhook_logs ALTER COLUMN tenant_id TYPE char(26) USING NULL');
        } else {
            DB::table('webhook_logs')->update(['tenant_id' => null]);
            Schema::table('webhook_logs', function (Blueprint $table) {
                $table->ulid('tenant_id')->nullable()->change();
            });
        }
    }
};
