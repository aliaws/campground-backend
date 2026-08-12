<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghl_sync_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id');
            $table->string('status')->default('success'); // success | failed | partial
            $table->string('triggered_by')->default('manual'); // manual | scheduled

            $table->unsignedInteger('total_contacts_pulled')->default(0);
            $table->unsignedInteger('total_categories_pulled')->default(0);
            $table->unsignedInteger('total_products_pulled')->default(0);
            $table->unsignedInteger('total_services_pulled')->default(0);
            $table->unsignedInteger('total_rentals_pulled')->default(0);
            $table->unsignedInteger('total_paid_bookings_pulled')->default(0);
            $table->unsignedInteger('total_paid_invoices_pulled')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->text('error_message')->nullable();
            $table->json('phase_errors')->nullable();

            $table->timestamps();

            $table->index('engage_organization_location_id');
            $table->index(['engage_organization_location_id', 'created_at']);
            $table->foreign('engage_organization_location_id', 'ghl_sync_logs_eol_fk')
                ->references('id')->on('engage_organization_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghl_sync_logs');
    }
};
