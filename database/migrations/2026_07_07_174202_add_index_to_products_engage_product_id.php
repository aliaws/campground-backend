<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * createLocalStubIfMissing() in GhlProductSyncService does an existence
     * check on this column once per product in the GHL catalog on every
     * bulk pull — was previously an unindexed scan.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('engage_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['engage_product_id']);
        });
    }
};
