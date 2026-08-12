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
            // The location whose GHL contact sync determined this customer
            // no longer exists there — a customer can belong to multiple
            // locations (customers_locations junction), so archiving is
            // scoped per location, not per customer. See
            // CustomerService::archiveCustomer()'s doc comment.
            $table->uuid('engage_organization_location_id');
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

            $table->index('engage_organization_location_id');
            $table->index('customer_id');
            $table->index('ghl_contact_id');
            $table->foreign('engage_organization_location_id', 'customer_archives_eol_fk')
                ->references('id')->on('engage_organization_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_archives');
    }
};
