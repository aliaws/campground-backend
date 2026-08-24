<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional override for the OAuth redirect_uri, editable on the
 * super-admin-only global Engage Identifiers form — most deployments never
 * need to touch it, since SettingsController falls back to
 * config('app.url').'/api/v1/settings/engage/callback' when this is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->string('redirect_uri')->nullable()->after('api_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn('redirect_uri');
        });
    }
};
