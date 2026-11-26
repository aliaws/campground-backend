<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HighLevel Marketplace clean-up, step A5: page text from the spec.
 *
 * - About Us: the software sales pitch (Camp Manager, free 90-day pilot,
 *   "book a walkthrough") is replaced with the Whispering Pines About text.
 * - FAQ: the eight software-sales questions are replaced with the spec's six
 *   campground questions plus one pointing to the cancellation policy.
 * - New public page "Cancellation Policy" (slug cancellation-policy, edited
 *   in Site Pages like Terms of Service) with the spec's policy, linked from
 *   the footer's Legal column.
 * - Terms of Service section 4 now points to that page instead of vague
 *   "terms shown at the time of booking" (only if the old paragraph is still
 *   there unchanged).
 *
 * Terms, Privacy and Support had no agency or pilot text, so they are
 * otherwise untouched. down() puts back the About and FAQ text that was live
 * on 2026-11-26, restores Terms section 4, removes the footer link and
 * deletes the Cancellation Policy page.
 */
return new class extends Migration
{
    private const CANCELLATION_SLUG = 'cancellation-policy';

    private const CANCELLATION_HREF = '/cancellation-policy';

    private const ABOUT_NEW = <<<'HTML'
<h2>Your Peaceful Escape in the Pines</h2><p>Tucked away among towering evergreens, Whispering Pines Campground offers a quiet, family-friendly retreat for RV travelers, tent campers, and cabin guests alike. Whether you are planning a weekend getaway or an extended stay, our shaded sites, clean facilities, and easy online reservations make it simple to unplug and enjoy the outdoors.</p><h2>What We Offer</h2><ul><li><p><strong>Full-Hookup RV Sites:</strong> Pull-through and back-in options with 30/50 amp service.</p></li><li><p><strong>Rustic Cabins &amp; Tent Sites:</strong> Private, wooded spots with fire rings and picnic tables.</p></li><li><p><strong>Instant Online Reservations:</strong> Real-time availability and automated check-in details.</p></li></ul><h2>Plan Your Stay</h2><p>Check availability and reserve your site online, or reach our office through the <a href="/contact">Contact page</a>.</p>
HTML;

    private const ABOUT_OLD = <<<'HTML'
<p>Camp Manager runs your whole campground from one screen: online booking, front desk, payments, and reports.</p><h2>Why Camp Manager</h2><p>Most campgrounds juggle a booking site, a paper chart, a card reader, and a spreadsheet. Camp Manager puts them together. A guest books online, the site is held automatically, the payment is recorded, and the reservation shows up on your calendar, so nothing gets typed twice.</p><h2>What you get</h2><ul><li><p>Online booking that shows only the sites that are open, so double bookings are stopped at the source</p></li><li><p>A front desk screen for calls and walk-ups</p></li><li><p>A booking inquiry pipeline for groups and special requests</p></li><li><p>A property map and campsite list you set up once</p></li><li><p>An owner dashboard and reports for revenue, occupancy, and arrivals</p></li></ul><h2>Try it free for 90 days</h2><p>We are inviting a small number of campgrounds to run a free 90-day pilot. We set it up with you, match it to your brand or connect it to your existing site, and you decide whether to keep it. This site is a live demo, so click around.</p><h2>Get in Touch</h2><p>Ready for a walkthrough? Use the Schedule Demo page, or reach us on the Contact page.</p>
HTML;

    private const FAQ_NEW = <<<'JSON'
[
    {
        "id": "check-in-check-out-times",
        "question": "What are check-in and check-out times?",
        "answer": "<p><strong>Check-in:</strong> 2:00 PM for tent and RV sites; 3:00 PM for cabins. <strong>Check-out:</strong> 11:00 AM for all sites and cabins. Early check-in or late check-out may be available on request, depending on site availability.</p>",
        "sort_order": 1
    },
    {
        "id": "types-of-sites",
        "question": "What types of sites do you offer?",
        "answer": "<p>We offer full-hookup RV sites (30/50 amp, water, and sewer), pull-thru RV sites, electric-only sites, wooded and lakeside tent sites, and rustic cabins.</p>",
        "sort_order": 2
    },
    {
        "id": "pets-allowed",
        "question": "Are pets allowed?",
        "answer": "<p>Yes! Well-behaved, leashed pets are welcome at all tent and RV sites (and select pet-friendly cabins). Please clean up after your pets and never leave them unattended outside.</p>",
        "sort_order": 3
    },
    {
        "id": "campfires",
        "question": "Are campfires permitted?",
        "answer": "<p>Campfires are allowed in designated fire rings only, subject to local seasonal fire restrictions. Firewood is available for purchase at the Camp Store. Please do not bring outside firewood, to help protect our local trees.</p>",
        "sort_order": 4
    },
    {
        "id": "quiet-hours",
        "question": "What are quiet hours?",
        "answer": "<p>Quiet hours are observed daily from 10:00 PM to 7:00 AM. Please keep music, generators, and voices low during these hours.</p>",
        "sort_order": 5
    },
    {
        "id": "reserve-or-modify",
        "question": "How do I reserve or modify a stay?",
        "answer": "<p>Check real-time availability and reserve directly on our website with instant confirmation. To modify an existing reservation, log in to your Customer Login portal or contact our office at (555) 014-2890 or hello@whisperingpines-demo.example.</p>",
        "sort_order": 6
    },
    {
        "id": "cancellation-policy",
        "question": "What is your cancellation policy?",
        "answer": "<p>Refunds depend on the type of site and how far ahead you cancel. See our <a href=\"/cancellation-policy\">Cancellation Policy</a> for the full details.</p>",
        "sort_order": 7
    }
]
JSON;

    private const FAQ_OLD = <<<'JSON'
[
    {
        "id": "how-do-i-book-a-rental",
        "question": "What is Camp Manager?",
        "answer": "Browse available cabins, campsites, and RV sites from the homepage, pick your check-in and check-out dates, and follow the checkout steps. You'll get a confirmation by email — some bookings confirm instantly, while others go through a brief review by our team first.",
        "sort_order": 1
    },
    {
        "id": "do-i-need-to-create-an-account-to-book",
        "question": "What does the free 90-day pilot include?",
        "answer": "No — you can book as a guest with just your name, email, and phone number. An account is created for you automatically the first time you book, so you can come back later, use the same email, and set a password to sign in and manage your reservations.",
        "sort_order": 2
    },
    {
        "id": "what-payment-methods-do-you-accept",
        "question": "Can it match our brand or work with our existing website?",
        "answer": "We accept major credit and debit cards through our secure online checkout. Depending on the listing, cash payment in person may also be available — this is shown as a payment option at checkout when applicable.",
        "sort_order": 3
    },
    {
        "id": "when-is-my-card-actually-charged",
        "question": "How do guests book and pay?",
        "answer": "<p>Guests pick their dates, see only the sites that are open, and check out with a name, email, and optional phone number. A secure payment link goes to their inbox and the reservation lands in your system.</p>",
        "sort_order": 4
    },
    {
        "id": "is-a-security-deposit-required",
        "question": "Does it handle phone calls and walk-ups?",
        "answer": "Some listings require a refundable security deposit, shown in your quote and invoice before you complete a booking. Deposits are refunded after checkout provided there's no damage, excessive cleaning needed, or policy violations.",
        "sort_order": 5
    },
    {
        "id": "can-i-change-or-cancel-my-reservation",
        "question": "How does it prevent double bookings?",
        "answer": "<p>Only sites that are open for the chosen dates are shown, online and at the front desk, so a taken site never appears as available.</p>",
        "sort_order": 6
    },
    {
        "id": "how-do-i-find-my-booking-confirmation-or-invoice",
        "question": "What do I see as the owner?",
        "answer": "<p>A dashboard with revenue, active bookings, and customers, plus reports on occupancy, check-ins, average stay, and revenue by site type, and a property map showing what is booked and open.</p>",
        "sort_order": 7
    },
    {
        "id": "i-didnt-receive-a-confirmation-email-what-do-i-do",
        "question": "How do I get started?",
        "answer": "<p>Use the Schedule Demo page to book a walkthrough, or reach us on the Contact page. We will confirm the pilot details with you on the call.</p>",
        "sort_order": 8
    }
]
JSON;

    private const CANCELLATION_BODY = <<<'HTML'
<h2>Standard Sites (Tent &amp; RV)</h2><ul><li><p>7 or more days before check-in: full refund minus a $10 administrative fee.</p></li><li><p>3–6 days before check-in: 50% refund, or 100% camp credit valid for 12 months.</p></li><li><p>Less than 72 hours before check-in: non-refundable.</p></li></ul><h2>Cabins &amp; Holiday Weekends</h2><ul><li><p>14 or more days before check-in: full refund minus a $20 administrative fee.</p></li><li><p>7–13 days before check-in: 50% refund, or 100% camp credit valid for 12 months.</p></li><li><p>Less than 7 days before check-in: non-refundable.</p></li></ul><h2>Additional Policies</h2><ul><li><p>Reservations not claimed within 24 hours of check-in without notice are treated as no-shows and forfeited.</p></li><li><p>No refunds for early departures or inclement weather, unless the campground closes for safety.</p></li><li><p>Submit cancellation requests through the Customer Login portal, or email hello@whisperingpines-demo.example with your reservation number.</p></li></ul><p><em>Demo notice: this policy is sample text provided for demonstration purposes only.</em></p>
HTML;

    private const TERMS_SECTION_4_NEW = <<<'HTML'
<p>Cancellation and refund terms depend on the type of site and how far ahead you cancel. See our <a href="/cancellation-policy">Cancellation Policy</a> for the full details. Contact us as soon as possible if your plans change.</p>
HTML;

    private const TERMS_SECTION_4_OLD = <<<'HTML'
<p>Cancellation and refund terms are shown at the time of booking and may vary by listing and season. Unless stated otherwise for your specific reservation, cancellations made close to the check-in date may be subject to reduced or no refund. Contact us as soon as possible if your plans change — we will always try to work with you.</p>
HTML;

    public function up(): void
    {
        $this->editPage('about-us', fn (array $c) => ['body' => self::ABOUT_NEW] + $c);
        $this->editPage('faq', fn (array $c) => ['items' => json_decode(self::FAQ_NEW, true)] + $c);
        $this->editPage('terms-of-service', fn (array $c) => $this->swapTerms($c, self::TERMS_SECTION_4_OLD, self::TERMS_SECTION_4_NEW));

        if (! DB::table('cms_pages')->where('slug', self::CANCELLATION_SLUG)->exists()) {
            DB::table('cms_pages')->insert([
                'id' => (string) Str::ulid(),
                'slug' => self::CANCELLATION_SLUG,
                'title' => 'Cancellation Policy',
                'content' => json_encode(['body' => self::CANCELLATION_BODY]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('cms:page:'.self::CANCELLATION_SLUG);
        }

        $this->editPage('footer', function (array $c) {
            $items = $c['sections']['legal']['items'] ?? null;
            if (! is_array($items)) {
                return $c;
            }
            foreach ($items as $item) {
                if (($item['href'] ?? null) === self::CANCELLATION_HREF) {
                    return $c;
                }
            }
            $items[] = [
                'id' => 'cancellation-policy',
                'label' => 'Cancellation Policy',
                'href' => self::CANCELLATION_HREF,
                'sort_order' => (int) collect($items)->max('sort_order') + 1,
            ];
            $c['sections']['legal']['items'] = $items;

            return $c;
        });
    }

    public function down(): void
    {
        $this->editPage('about-us', fn (array $c) => ['body' => self::ABOUT_OLD] + $c);
        $this->editPage('faq', fn (array $c) => ['items' => json_decode(self::FAQ_OLD, true)] + $c);
        $this->editPage('terms-of-service', fn (array $c) => $this->swapTerms($c, self::TERMS_SECTION_4_NEW, self::TERMS_SECTION_4_OLD));

        DB::table('cms_pages')->where('slug', self::CANCELLATION_SLUG)->delete();
        Cache::forget('cms:page:'.self::CANCELLATION_SLUG);

        $this->editPage('footer', function (array $c) {
            if (isset($c['sections']['legal']['items']) && is_array($c['sections']['legal']['items'])) {
                $c['sections']['legal']['items'] = array_values(array_filter(
                    $c['sections']['legal']['items'],
                    fn ($item) => ($item['href'] ?? null) !== self::CANCELLATION_HREF
                ));
            }

            return $c;
        });
    }

    private function swapTerms(array $content, string $from, string $to): array
    {
        if (isset($content['body']) && is_string($content['body'])) {
            $content['body'] = str_replace($from, $to, $content['body']);
        }

        return $content;
    }

    private function editPage(string $slug, callable $edit): void
    {
        $page = DB::table('cms_pages')->where('slug', $slug)->first();
        if (! $page) {
            return;
        }

        $content = json_decode($page->content, true) ?: [];
        $updated = $edit($content);
        if ($updated === $content) {
            return;
        }

        DB::table('cms_pages')->where('slug', $slug)->update([
            'content' => json_encode($updated),
            'updated_at' => now(),
        ]);
        // The public site reads CMS pages from cache.
        Cache::forget('cms:page:'.$slug);
    }
};
