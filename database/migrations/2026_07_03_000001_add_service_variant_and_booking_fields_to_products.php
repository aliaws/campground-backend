<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // GHL Services/Rentals model: each service variant is its own product
            // record linked to the base product (mirrors service.variantId).
            $table->ulid('parent_product_id')->nullable()->after('product_type');
            $table->string('variant_name')->nullable()->after('parent_product_id');

            // GHL Calendar service/variant record _id (separate from engage_product_id,
            // which is the Payments-layer productId).
            $table->string('ghl_service_id')->nullable()->after('engage_product_id');

            // Booking configuration (from GHL service payload)
            $table->string('booking_unit')->nullable()->after('campsite_status'); // day
            $table->integer('min_duration')->nullable()->after('booking_unit');
            $table->integer('max_duration')->nullable()->after('min_duration');
            $table->string('duration_unit')->nullable()->after('max_duration'); // day
            $table->string('booking_start_time')->nullable()->after('duration_unit'); // "09:00"
            $table->string('booking_end_time')->nullable()->after('booking_start_time'); // "17:00"
            $table->integer('max_quantity')->nullable()->after('booking_end_time');

            $table->foreign('parent_product_id')->references('id')->on('products')->nullOnDelete();
            $table->index('parent_product_id');
            $table->index('ghl_service_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_product_id']);
            $table->dropIndex(['parent_product_id']);
            $table->dropIndex(['ghl_service_id']);

            $table->dropColumn([
                'parent_product_id', 'variant_name', 'ghl_service_id',
                'booking_unit', 'min_duration', 'max_duration', 'duration_unit',
                'booking_start_time', 'booking_end_time', 'max_quantity',
            ]);
        });
    }
};
