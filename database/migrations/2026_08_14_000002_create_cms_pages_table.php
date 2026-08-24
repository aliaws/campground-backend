<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide CMS content — Terms of Service, Privacy Policy, Support,
 * About Us, Contact Us. Genuinely global (super-admin only), same reasoning
 * as engage_settings: one set of legal/support pages for the whole
 * platform, not per-organization. `content` is a single JSON column
 * deliberately (not one column per field) — its shape varies by slug:
 * {"body": "..."} for the four freeform pages, {"phone","email","address",
 * "text"} for contact-us. One table, one column, differentiated by slug —
 * no per-field columns to keep in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('title');
            $table->json('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
