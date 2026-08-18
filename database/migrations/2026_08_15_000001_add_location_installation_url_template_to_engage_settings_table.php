<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GHL marketplace app's "install to a location" URL, with a literal
 * {{location_id}} placeholder — e.g.
 * https://app.gohighlevel.com/v2/location/{{location_id}}/integration/<app-id>/versions/<version-id>
 * A visitor pastes in a 20-char alphanumeric location id on the public
 * "Register for Application" page and gets redirected to this URL with
 * the placeholder substituted (see PublicEngageController, not this
 * migration's concern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->string('location_installation_url_template')->nullable()->after('redirect_uri');
        });
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn('location_installation_url_template');
        });
    }
};
