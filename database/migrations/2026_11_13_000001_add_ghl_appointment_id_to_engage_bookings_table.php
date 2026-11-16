<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lead Connector calendar event id of a booking's appointment. Lead
 * Connector's appointment webhooks identify the appointment by this id, not
 * by the service booking id stored in ghl_booking_id, so a deleted
 * appointment can only be traced back to its booking through this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->string('ghl_appointment_id')->nullable()->after('ghl_booking_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('engage_bookings', function (Blueprint $table) {
            $table->dropColumn('ghl_appointment_id');
        });
    }
};
