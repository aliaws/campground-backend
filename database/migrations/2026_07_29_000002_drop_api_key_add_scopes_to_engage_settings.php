<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn('api_key');
            $table->json('scopes')->nullable()->after('timezone');
        });

        $defaultScopes = [
            'contacts.readonly',
            'contacts.write',
            'products.readonly',
            'products.write',
            'products/prices.readonly',
            'products/prices.write',
            'products/collection.readonly',
            'products/collection.write',
            'invoices.readonly',
            'invoices.write',
            'invoices/schedule.readonly',
            'invoices/schedule.write',
            'calendars.readonly',
            'calendars.write',
            'calendars/events.readonly',
            'calendars/events.write',
            'calendars/resources.readonly',
        ];

        DB::table('engage_settings')
            ->whereNull('scopes')
            ->update(['scopes' => json_encode($defaultScopes)]);
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn('scopes');
            $table->string('api_key')->nullable()->after('tenant_id');
        });
    }
};
