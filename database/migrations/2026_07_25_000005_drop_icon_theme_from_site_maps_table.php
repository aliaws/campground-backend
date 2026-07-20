<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->dropColumn('icon_theme');
        });
    }

    public function down(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->string('icon_theme')->default('default')->after('image_url');
        });
    }
};
