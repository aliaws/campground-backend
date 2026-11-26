<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HighLevel Marketplace clean-up, step A4: replace the agency's real contact
 * details (Accurate Digital Solutions email and phone) with clearly fictional
 * Whispering Pines demo details on the Contact Us page and the site footer.
 * The footer is also shown on the staff /login page.
 *
 * - Contact Us: phone, email, address, and the intro text (was a sales line,
 *   now a campground office note with opening hours).
 * - Footer: phone, email, address, brand blurb, and the bottom line
 *   ("bookings" -> "reservations", plus "All rights reserved.").
 *
 * down() puts back the values that were live on 2026-11-26.
 */
return new class extends Migration
{
    private const NEW_CONTACT = [
        'phone' => '(555) 014-2890',
        'email' => 'hello@whisperingpines-demo.example',
        'address' => '1240 Pine Ridge Trail, Pinecrest, CA 95364',
        'text' => '<p>Questions about a reservation, a site or your stay? Call or email our office and we will be glad to help.</p><p><strong>Office hours:</strong> Daily, 8:00 AM – 7:00 PM</p>',
    ];

    private const NEW_FOOTER = [
        'phone' => '(555) 014-2890',
        'email' => 'hello@whisperingpines-demo.example',
        'address' => '1240 Pine Ridge Trail, Pinecrest, CA 95364',
        'description' => 'Family-friendly RV sites, tent camping, and cozy cabins with instant online reservations.',
        'copyright_text' => '© {year} Whispering Pines Campground. All rights reserved. All reservations are subject to our cancellation policy.',
    ];

    private const OLD_CONTACT = [
        'phone' => '+1 9169080860',
        'email' => 'hello@accuratedigitalsolutions.com',
        'address' => '300 Forest Edge Road, Folsom Lake, CA 95630',
        'text' => "<p>Questions about whether this would work for you? Send us an email, and we'll set up a time.</p>",
    ];

    private const OLD_FOOTER = [
        'phone' => '+1 9169080860',
        'email' => 'hello@accuratedigitalsolutions.com',
        'address' => '300 Forest Edge Road, Folsom Lake, CA 95630',
        'description' => 'Cabins, campsites and glamping stays — book your next escape in minutes and pay securely online.',
        'copyright_text' => '© {year} Whispering Pines Campground. All bookings are subject to our cancellation policy.',
    ];

    public function up(): void
    {
        $this->merge('contact-us', self::NEW_CONTACT);
        $this->merge('footer', self::NEW_FOOTER);
    }

    public function down(): void
    {
        $this->merge('contact-us', self::OLD_CONTACT);
        $this->merge('footer', self::OLD_FOOTER);
    }

    private function merge(string $slug, array $values): void
    {
        $page = DB::table('cms_pages')->where('slug', $slug)->first();
        if (! $page) {
            return;
        }

        $content = array_merge(json_decode($page->content, true) ?: [], $values);
        DB::table('cms_pages')->where('slug', $slug)->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
        // The public site reads CMS pages from cache.
        Cache::forget('cms:page:'.$slug);
    }
};
