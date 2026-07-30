<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Drop unused DB cache tables — cache store is Redis. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }

    public function down(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }
};
