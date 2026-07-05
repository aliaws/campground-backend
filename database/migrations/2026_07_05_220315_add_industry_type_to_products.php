<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // GHL Calendar service industryType (e.g. "rental", "salon", "fitness").
            // Stored per-row since each service/variant carries its own value.
            $table->string('industry_type')->nullable()->after('ghl_service_id');
            $table->index('industry_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['industry_type']);
            $table->dropColumn('industry_type');
        });
    }
};
