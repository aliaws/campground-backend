<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom code snippets (tracking/chat widgets, etc.) the superadmin adds to
 * the customer website and/or the staff portal. Platform-global, like the
 * CMS pages: no organization column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_scripts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->text('code');
            // head | body_start | body_end
            $table->string('placement', 20);
            // customer | staff | both
            $table->string('target', 20);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_scripts');
    }
};
