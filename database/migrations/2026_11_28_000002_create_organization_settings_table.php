<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HighLevel Marketplace clean-up, step D1: per-campground settings (brand
 * images, appearance, receipt notes, tax label/rate, currency, support
 * contact). One row per organization, created on first save.
 *
 * Name, email, phone, address and timezone are NOT here: they already live
 * on engage_organization_locations (the business profile) and are reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id')->unique();
            $table->foreign('engage_organization_location_id')
                ->references('id')->on('engage_organization_locations')->cascadeOnDelete();

            // Brand identity (the brand name is the organization's own name).
            $table->string('brand_subtitle', 150)->nullable();
            $table->string('logo_light_url', 2048)->nullable();
            $table->string('logo_dark_url', 2048)->nullable();
            $table->string('favicon_url', 2048)->nullable();

            // Appearance.
            $table->string('accent_color', 7)->nullable();
            $table->string('default_theme', 10)->nullable(); // light | dark, null = each user's own choice
            $table->boolean('hide_empty_categories')->default(true);

            // Receipts (contact details come from the business profile).
            $table->text('receipt_header_note')->nullable();
            $table->text('receipt_footer_note')->nullable();
            $table->string('tax_label', 50)->nullable();
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->string('currency', 3)->default('USD');

            // Agency & support.
            $table->string('support_url', 2048)->nullable();
            $table->string('support_email')->nullable();
            $table->boolean('show_footer_attribution')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
