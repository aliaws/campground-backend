<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->ulid('category_id')->nullable();
            $table->decimal('base_price', 10, 2)->default(0);
            $table->integer('stock_qty')->nullable()->comment('null = unlimited');
            $table->integer('capacity')->nullable()->comment('persons - campsite only');
            $table->string('location')->nullable()->comment('site/slot location - campsite only');
            $table->string('rental_duration_unit')->nullable();
            $table->integer('min_rental_duration')->nullable();
            $table->integer('max_rental_duration')->nullable();
            $table->string('status')->default('available');
            $table->string('image_url')->nullable();
            $table->ulid('tenant_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->index('tenant_id');
            $table->index('type');
            $table->index('sub_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
