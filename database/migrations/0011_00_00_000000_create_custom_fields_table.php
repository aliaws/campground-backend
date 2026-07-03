<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('entity_type');
            $table->string('field_name');
            $table->string('field_type');
            $table->ulid('tenant_id');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
