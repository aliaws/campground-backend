<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Which rental variant was booked (product_id now always points at the
            // base listing). Nullable: legacy reservations on local-only products.
            $table->ulid('product_rental_id')->nullable()->index();
            // Check-in-after / check-out-before, snapshotted from the live GHL
            // detail at creation time so the guest confirmation endpoint (polled
            // every 5s) never needs a GHL call and survives later GHL edits.
            $table->string('booking_start_time')->nullable();
            $table->string('booking_end_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['product_rental_id', 'booking_start_time', 'booking_end_time']);
        });
    }
};
