<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ghl_sync_logs', function (Blueprint $table) {
            $table->unsignedInteger('total_service_categories_pulled')->default(0)->after('total_categories_pulled');
        });
    }

    public function down(): void
    {
        Schema::table('ghl_sync_logs', function (Blueprint $table) {
            $table->dropColumn('total_service_categories_pulled');
        });
    }
};
