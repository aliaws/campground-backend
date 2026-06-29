<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->string('recurring_interval')->nullable()->after('available_quantity');
            $table->integer('recurring_interval_count')->nullable()->after('recurring_interval');
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn(['recurring_interval', 'recurring_interval_count']);
        });
    }
};
