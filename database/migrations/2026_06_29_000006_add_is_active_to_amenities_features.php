<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('icon');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
