<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn(['label', 'price', 'valid_from', 'valid_until']);
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->string('name')->after('product_id');
            $table->string('type')->default('one_time')->after('name');
            $table->decimal('amount', 10, 2)->after('type');
            $table->string('currency')->default('USD')->after('amount');
            $table->ulid('variation_id')->nullable()->after('currency');
            $table->boolean('track_inventory')->default(false)->after('variation_id');
            $table->integer('available_quantity')->nullable()->after('track_inventory');
            $table->string('sku')->nullable()->after('available_quantity');
            $table->boolean('deleted')->default(false)->after('sku');
            $table->string('engage_price_id')->nullable()->after('deleted');
            $table->string('engage_sync_status')->default('not_synced')->after('engage_price_id');
            $table->string('sync_error_message')->nullable()->after('engage_sync_status');

            $table->foreign('variation_id')->references('id')->on('product_variations')->onDelete('set null');
            $table->index('engage_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropForeign(['variation_id']);
            $table->dropIndex(['engage_sync_status']);
            $table->dropColumn([
                'name', 'type', 'amount', 'currency', 'variation_id',
                'track_inventory', 'available_quantity', 'sku', 'deleted',
                'engage_price_id', 'engage_sync_status', 'sync_error_message',
            ]);
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->string('label')->after('product_id');
            $table->decimal('price', 10, 2)->after('label');
            $table->date('valid_from')->nullable()->after('price');
            $table->date('valid_until')->nullable()->after('valid_from');
        });
    }
};
