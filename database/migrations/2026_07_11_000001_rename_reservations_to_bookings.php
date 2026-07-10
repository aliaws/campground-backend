<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
        });

        Schema::rename('reservations', 'bookings');

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('reservation_id', 'booking_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
        });

        if (Schema::hasTable('custom_fields')) {
            DB::table('custom_fields')
                ->where('entity_type', 'reservation')
                ->update(['entity_type' => 'booking']);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('booking_id', 'reservation_id');
        });

        Schema::rename('bookings', 'reservations');

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('reservations')->nullOnDelete();
        });

        if (Schema::hasTable('custom_fields')) {
            DB::table('custom_fields')
                ->where('entity_type', 'booking')
                ->update(['entity_type' => 'reservation']);
        }
    }
};
