<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an owner/admin curate which POS (industry_type='pos') categories
     * appear in the guest homepage's "Shop by Category" showcase — separate
     * from is_active (which governs whether a category is usable/visible at
     * all everywhere else). Ordering reuses the existing sort_order column
     * rather than adding a second one.
     */
    public function up(): void
    {
        Schema::table('engage_categories', function (Blueprint $table) {
            $table->boolean('show_on_homepage')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('engage_categories', function (Blueprint $table) {
            $table->dropColumn('show_on_homepage');
        });
    }
};
