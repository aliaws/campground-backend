<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step C3: "Booking" -> "Reservation" in
 * the sidebar. Menu Manager rows override the labels written in code, so the
 * three booking rows are renamed here too. A label the superadmin changed
 * (no longer equal to its default) is left as it is; the default label is
 * always updated so the Menu Manager shows the new default.
 *
 * Only labels change here; the keys (/pos/bookings, ...) stay until the page
 * addresses are renamed.
 */
return new class extends Migration
{
    /** key => [old default, new default] */
    private const LABELS = [
        'group:booking' => ['Booking', 'Reservation'],
        '/pos/bookings/new' => ['New Booking', 'New Reservation'],
        '/pos/bookings' => ['Bookings', 'Reservations'],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            $this->relabel($key, $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            $this->relabel($key, $new, $old);
        }
    }

    private function relabel(string $key, string $from, string $to): void
    {
        DB::table('engage_menu_items')->where('key', $key)->where('label', $from)->update(['label' => $to]);
        DB::table('engage_menu_items')->where('key', $key)->update(['default_label' => $to, 'updated_at' => now()]);
    }
};
