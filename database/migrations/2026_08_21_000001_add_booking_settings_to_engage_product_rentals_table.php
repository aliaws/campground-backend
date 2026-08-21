<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking-period configuration pulled from GHL's `calendars/services/{id}`
 * detail endpoint (bookingPeriodType, min/max duration, pre/post buffer,
 * scheduling-notice/advance-window, fixed-duration intervals, etc.) — see
 * Manage Service's new "Booking Settings" tab. Only the selected
 * `bookingPeriodType` gets its own column (it drives which fields the edit
 * form shows/hides); everything else is stored as one JSON object rather
 * than a column per setting, per the feature's own explicit ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->string('booking_period_type')->nullable()->after('security_deposit_amount');
            $table->json('booking_settings')->nullable()->after('booking_period_type');
        });
    }

    public function down(): void
    {
        Schema::table('engage_product_rentals', function (Blueprint $table) {
            $table->dropColumn(['booking_period_type', 'booking_settings']);
        });
    }
};
