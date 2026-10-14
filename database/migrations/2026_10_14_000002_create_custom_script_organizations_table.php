<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which organizations a custom script is limited to. No rows for a script
 * means it runs for every organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_script_organizations', function (Blueprint $table) {
            $table->ulid('custom_script_id');
            $table->uuid('engage_organization_location_id');

            $table->primary(['custom_script_id', 'engage_organization_location_id']);
            $table->index('engage_organization_location_id', 'custom_script_organizations_org_idx');
            $table->foreign('custom_script_id')->references('id')->on('custom_scripts')->cascadeOnDelete();
            $table->foreign('engage_organization_location_id', 'custom_script_organizations_org_fk')
                ->references('id')->on('engage_organization_locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_script_organizations');
    }
};
