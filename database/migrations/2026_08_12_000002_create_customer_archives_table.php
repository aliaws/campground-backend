<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_archives', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            // The original customers.id — kept so restoreFromArchive() can
            // un-trash the exact same row (preserving every Booking/
            // RentalTransaction/ProductTransaction FK) instead of recreating
            // a new customer with no history. Nullable defensively, though
            // every row created by CustomerService::archiveCustomer() always
            // sets it.
            $table->ulid('customer_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->string('ghl_contact_id')->nullable();
            $table->string('ghl_sync_status')->nullable();
            $table->timestamp('ghl_last_synced_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('archived_at');
            $table->string('archived_reason')->default('ghl_removed');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('customer_id');
            $table->index('ghl_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_archives');
    }
};
