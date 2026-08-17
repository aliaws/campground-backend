<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service organization registration (public "Register for
 * Application" flow) creates an EngageOrganizationLocation row before any
 * business info is known — just engage_location_id + status=uninstalled —
 * so `name` ("No Name yet") can no longer be required at creation time.
 * The Complete Registration step fills it in afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
