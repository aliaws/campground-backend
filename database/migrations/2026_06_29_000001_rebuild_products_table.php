<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'type', 'sub_type', 'category_id', 'base_price', 'stock_qty',
                'location', 'rental_duration_unit', 'min_rental_duration',
                'max_rental_duration', 'image_url',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type')->default('PHYSICAL')->after('name');
            $table->text('description')->nullable()->after('product_type');
            $table->string('sku')->nullable()->after('description');
            $table->boolean('is_variable')->default(false)->after('status');
            $table->boolean('available_in_store')->default(true)->after('is_variable');
            $table->string('image')->nullable()->after('available_in_store');
            $table->string('thumbnail')->nullable()->after('image');
            $table->json('medias')->nullable()->after('thumbnail');
            $table->integer('display_priority')->default(0)->after('medias');
            $table->boolean('tax_inclusive')->default(false)->after('display_priority');
            $table->boolean('is_taxes_enabled')->default(false)->after('tax_inclusive');

            // SERVICE-type fields (campsite)
            $table->string('site_type')->nullable()->after('is_taxes_enabled');
            $table->integer('available_quantity')->nullable()->after('capacity');
            $table->json('hookups')->nullable()->after('available_quantity');
            $table->json('map_position')->nullable()->after('hookups');
            $table->json('map_polygon')->nullable()->after('map_position');
            $table->boolean('pet_friendly')->default(false)->after('map_polygon');
            $table->boolean('ada_accessible')->default(false)->after('pet_friendly');
            $table->string('campsite_status')->nullable()->default('available')->after('ada_accessible');

            // GHL sync fields
            $table->string('engage_product_id')->nullable()->after('tenant_id');
            $table->string('engage_sync_status')->default('not_synced')->after('engage_product_id');
            $table->timestamp('engage_last_synced_at')->nullable()->after('engage_sync_status');

            $table->index('product_type');
            $table->index('engage_sync_status');
        });

        // Update status column default
        Schema::table('products', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropIndex(['engage_sync_status']);

            $table->dropColumn([
                'product_type', 'description', 'sku', 'is_variable',
                'available_in_store', 'image', 'thumbnail', 'medias',
                'display_priority', 'tax_inclusive', 'is_taxes_enabled',
                'site_type', 'available_quantity', 'hookups',
                'map_position', 'map_polygon', 'pet_friendly', 'ada_accessible',
                'campsite_status', 'engage_product_id', 'engage_sync_status',
                'engage_last_synced_at',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->after('name');
            $table->string('sub_type')->nullable()->after('type');
            $table->ulid('category_id')->nullable()->after('sub_type');
            $table->decimal('base_price', 10, 2)->default(0)->after('category_id');
            $table->integer('stock_qty')->nullable()->after('base_price');
            $table->string('location')->nullable()->after('capacity');
            $table->string('rental_duration_unit')->nullable()->after('location');
            $table->integer('min_rental_duration')->nullable()->after('rental_duration_unit');
            $table->integer('max_rental_duration')->nullable()->after('min_rental_duration');
            $table->string('image_url')->nullable()->after('status');
            $table->string('status')->default('available')->change();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->index('type');
            $table->index('sub_type');
        });
    }
};
