<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `business_settings` is a genuinely orphaned table (found during a
 * 2026-08-10 unused-table audit): the `migrations` ledger records
 * `2026_07_26_000001_create_business_settings_table` as having run, but
 * that migration file, the `BusinessSetting` model, and every consumer
 * (controller/resource/route/frontend page) were deleted at some point
 * without a matching drop — confirmed via a full grep of `app/`, `routes/`,
 * and the entire frontend turning up zero references anywhere. Matches
 * CLAUDE.md's own "Custom Invoice Generation" correction note describing
 * this exact feature as designed-but-never-shipped.
 *
 * The table held exactly one real row at drop time (tenant
 * 01KVWTZ5AWMAWS0KWY4MCKKFY2, business_name "Pine Ridge Campground",
 * logo_path/phone/email/tax_id/invoice_terms set, address null) —
 * preserved here in this comment since down() only restores the empty
 * schema, not the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('business_settings');
    }

    public function down(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->string('business_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_id')->nullable();
            $table->text('invoice_terms')->nullable();
            $table->timestamps();
        });
    }
};
