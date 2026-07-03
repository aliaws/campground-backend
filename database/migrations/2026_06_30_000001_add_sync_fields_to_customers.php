<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ghl_sync_status')->default('not_synced')->after('ghl_contact_id');
            $table->timestamp('ghl_last_synced_at')->nullable()->after('ghl_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['ghl_sync_status', 'ghl_last_synced_at']);
        });
    }
};
