<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('ghl_booking_id')->nullable()->after('ghl_opportunity_id');
            $table->index('ghl_booking_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('ghl_service_category_id')->nullable()->after('ghl_service_id');
            $table->string('ghl_service_location_id')->nullable()->after('ghl_service_category_id');
            $table->json('ghl_metadata')->nullable()->after('ghl_service_location_id');
        });

        Schema::table('engage_settings', function (Blueprint $table) {
            $table->string('timezone')->default('America/New_York')->after('api_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['ghl_booking_id']);
            $table->dropColumn('ghl_booking_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ghl_service_category_id', 'ghl_service_location_id', 'ghl_metadata']);
        });

        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
