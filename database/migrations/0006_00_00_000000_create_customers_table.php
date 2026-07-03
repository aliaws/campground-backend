<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->string('ghl_contact_id')->nullable();
            $table->ulid('tenant_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('email');
            $table->index('ghl_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
