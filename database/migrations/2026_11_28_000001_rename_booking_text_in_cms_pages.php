<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step C8: "booking" -> "reservation" in the
 * CMS text saved in the database (home page heading + button, Shop subtext,
 * Terms, Privacy, Support, Schedule Demo). Each change is an exact phrase
 * taken from the live content on 2026-11-28, so text an admin has rewritten
 * is left alone. The Schedule Demo embed code (a widget URL containing
 * "booking") is never touched. down() swaps the phrases back.
 */
return new class extends Migration
{
    /** slug => [[old phrase, new phrase], ...] */
    private const RULES = [
        'home-page' => [
            ['Book a demo site the way your guests would', 'Reserve a demo site the way your guests would'],
            ['Book Site', 'Reserve Site'],
        ],
        'shop' => [
            ['add to your booking.', 'add to your reservation.'],
        ],
        'terms-of-service' => [
            ['this website and booking a campsite', 'this website and reserving a campsite'],
            ['18 years old to make a booking', '18 years old to make a reservation'],
            ['3. Bookings &amp; Reservations', '3. Reservations'],
            ['A booking request is not confirmed', 'A reservation request is not confirmed'],
            ['cancel any booking request', 'cancel any reservation request'],
            ['notice until a booking is confirmed', 'notice until a reservation is confirmed'],
            ['at the time of booking or check-in', 'at the time of reservation or check-in'],
            ['manage your bookings, you are', 'manage your reservations, you are'],
            ['false information when booking', 'false information when reserving'],
        ],
        'privacy-policy' => [
            ['browse or book campsites', 'browse or reserve campsites'],
            ['when you make a booking, create', 'when you make a reservation, create'],
            ['), booking details (dates', '), reservation details (dates'],
            ['process and manage bookings; communicate', 'process and manage reservations; communicate'],
            ['send booking-related emails', 'send reservation-related emails'],
            ['We share booking and contact', 'We share reservation and contact'],
            ['(used to manage bookings and', '(used to manage reservations and'],
            ['We retain booking and account', 'We retain reservation and account'],
        ],
        'support' => [
            ['related to your booking, your account', 'related to your reservation, your account'],
            ['How Do I Book a Site?', 'How Do I Reserve a Site?'],
            ['once your booking is finalized — some bookings are', 'once your reservation is finalized — some reservations are'],
            ['Cancel My Booking?', 'Cancel My Reservation?'],
            ['open the booking from &quot;My Bookings&quot;', 'open the reservation from &quot;My Reservations&quot;'],
            ['with your booking details', 'with your reservation details'],
            ['at the time of booking or check-in', 'at the time of reservation or check-in'],
            ['invoices for any booking from', 'invoices for any reservation from'],
            ['sign in to &quot;My Bookings&quot; to confirm', 'sign in to &quot;My Reservations&quot; to confirm'],
            ['email used for the booking and', 'email used for the reservation and'],
            ['first time you book as a guest', 'first time you reserve as a guest'],
            ['if you&#039;ve booked before', 'if you&#039;ve reserved before'],
            ['your name, booking reference', 'your name, reservation reference'],
        ],
        'schedule-demo' => [
            ['A guest booking a site online', 'A guest reserving a site online'],
            ['The booking pipeline', 'The reservation pipeline'],
            ['The guest booking experience', 'The guest reservation experience'],
        ],
    ];

    public function up(): void
    {
        foreach (self::RULES as $slug => $pairs) {
            $this->replace($slug, $pairs);
        }
    }

    public function down(): void
    {
        foreach (self::RULES as $slug => $pairs) {
            $this->replace($slug, array_map(fn ($p) => [$p[1], $p[0]], array_reverse($pairs)));
        }
    }

    private function replace(string $slug, array $pairs): void
    {
        $page = DB::table('cms_pages')->where('slug', $slug)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true);
        if (! is_array($content)) {
            return;
        }

        $updated = $this->walk($content, $pairs);
        if ($updated === $content) {
            return;
        }

        DB::table('cms_pages')->where('slug', $slug)->update([
            'content' => json_encode($updated),
            'updated_at' => now(),
        ]);
        Cache::forget('cms:page:'.$slug);
    }

    private function walk(mixed $value, array $pairs, int|string|null $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->walk($v, $pairs, $k);
            }

            return $value;
        }

        // Embed code holds a widget URL (".../widget/booking/..."), not text.
        if (! is_string($value) || $key === 'code') {
            return $value;
        }

        foreach ($pairs as [$from, $to]) {
            $value = str_replace($from, $to, $value);
        }

        return $value;
    }
};
