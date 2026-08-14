<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

/**
 * Seeds the five platform-wide CMS pages with real, usable starting content
 * (not lorem-ipsum placeholders) — super-admin can edit any of it afterward
 * via /superadmin/pages. Safe to re-run: updateOrCreate keyed by slug.
 */
class CmsPageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => CmsPage::SLUG_TERMS_OF_SERVICE,
                'title' => 'Terms of Service',
                'content' => ['body' => $this->toHtml($this->termsOfService())],
            ],
            [
                'slug' => CmsPage::SLUG_PRIVACY_POLICY,
                'title' => 'Privacy Policy',
                'content' => ['body' => $this->toHtml($this->privacyPolicy())],
            ],
            [
                'slug' => CmsPage::SLUG_SUPPORT,
                'title' => 'Support',
                'content' => ['body' => $this->toHtml($this->support())],
            ],
            [
                'slug' => CmsPage::SLUG_ABOUT_US,
                'title' => 'About Us',
                'content' => ['body' => $this->toHtml($this->aboutUs())],
            ],
            [
                'slug' => CmsPage::SLUG_CONTACT_US,
                'title' => 'Contact Us',
                'content' => [
                    'phone' => '(555) 012-3456',
                    'email' => 'stay@campgroundrentals.com',
                    'address' => '300 Forest Edge Road, Folsom Lake, CA 95630',
                    'text' => $this->toHtml("Questions about a booking, a site, or your reservation? Our team is happy to help.\n\nAlready have a booking request in? We'll follow up by email or phone shortly to confirm it. For anything urgent, calling is fastest."),
                ],
            ],
            [
                'slug' => CmsPage::SLUG_HEADER,
                'title' => 'Site Header',
                'content' => $this->headerContent(),
            ],
            [
                'slug' => CmsPage::SLUG_FOOTER,
                'title' => 'Site Footer',
                'content' => $this->footerContent(),
            ],
        ];

        foreach ($pages as $page) {
            CmsPage::query()->updateOrCreate(['slug' => $page['slug']], $page);
        }
    }

    /**
     * Content below is authored as plain text with a lightweight "## " =
     * heading convention (easier to write/review as a heredoc than inline
     * HTML) and converted to the HTML RichTextEditor/CmsContent actually
     * expect at seed time. Only used here, at seed time — the real content
     * lives as HTML in the database from this point on, edited via the
     * WYSIWYG editor in /superadmin/pages.
     */
    private function toHtml(string $plain): string
    {
        $blocks = preg_split('/\n\s*\n/', trim($plain));
        $html = '';

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (str_starts_with($block, '## ')) {
                $html .= '<h2>'.e(substr($block, 3)).'</h2>';
            } else {
                $html .= '<p>'.nl2br(e($block)).'</p>';
            }
        }

        return $html;
    }

    private function termsOfService(): string
    {
        return <<<'TEXT'
Last updated: August 14, 2026

## 1. Acceptance of Terms

By accessing or using this website and booking a campsite, cabin, RV site, or other rental listed here (the "Service"), you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use the Service.

## 2. Eligibility

You must be at least 18 years old to make a booking. By submitting a reservation, you confirm that you are of legal age to enter into a binding contract and that all information you provide is accurate and complete.

## 3. Bookings & Reservations

A booking request is not confirmed until you receive a confirmation notice from us. We reserve the right to decline or cancel any booking request, including for reasons such as unavailability, suspected fraud, or violation of these Terms. Prices, availability, and site details are subject to change without notice until a booking is confirmed and paid for.

## 4. Cancellations & Refunds

Cancellation and refund terms are shown at the time of booking and may vary by listing and season. Unless stated otherwise for your specific reservation, cancellations made close to the check-in date may be subject to reduced or no refund. Contact us as soon as possible if your plans change — we will always try to work with you.

## 5. Payments & Pricing

All prices are shown in U.S. dollars and include applicable taxes and fees unless stated otherwise. Payment is processed securely through our payment provider at the time of booking or check-in, depending on the option you select. Security deposits, where applicable, are refunded after checkout provided no damage, excessive cleaning, or policy violations occurred.

## 6. User Accounts

If you create an account to manage your bookings, you are responsible for maintaining the confidentiality of your login credentials and for all activity under your account. Notify us immediately if you suspect unauthorized use of your account.

## 7. Guest Responsibilities & Property Use

Guests agree to treat rental sites, shared facilities, and natural surroundings with care and respect. You are responsible for the conduct of everyone in your party, compliance with posted campground rules (including quiet hours, fire restrictions, and pet policies), and any damage caused during your stay.

## 8. Prohibited Conduct

You agree not to: misuse the Service for any unlawful purpose; attempt to interfere with or disrupt the Service; exceed the maximum occupancy or vehicle limits for a site; sublet or transfer a reservation without our consent; or provide false information when booking.

## 9. Liability & Disclaimers

Outdoor recreation carries inherent risks. To the fullest extent permitted by law, we are not liable for personal injury, loss, or property damage arising from your stay, except where caused by our proven negligence. The Service is provided "as is" without warranties of any kind, express or implied.

## 10. Intellectual Property

All content on this site — including text, photos, logos, and listing descriptions — is owned by us or our licensors and may not be copied or reused without permission.

## 11. Termination

We may suspend or terminate your access to the Service at any time for conduct that we believe violates these Terms or is otherwise harmful to other users, us, or third parties.

## 12. Governing Law

These Terms are governed by the laws of the state in which our business is registered, without regard to conflict-of-law principles.

## 13. Changes to These Terms

We may update these Terms from time to time. Continued use of the Service after changes are posted constitutes acceptance of the revised Terms. We'll update the "Last updated" date above whenever changes are made.

## 14. Contact Us

Questions about these Terms? Reach out via our Contact page and we'll be glad to help.
TEXT;
    }

    private function privacyPolicy(): string
    {
        return <<<'TEXT'
Last updated: August 14, 2026

## 1. Introduction

This Privacy Policy explains how we collect, use, and protect your personal information when you use this website to browse or book campsites, cabins, and other rentals. By using the Service, you consent to the practices described here.

## 2. Information We Collect

We collect information you provide directly, such as your name, email address, phone number, and mailing address when you make a booking, create an account, or contact us. We also collect payment information (processed securely by our payment provider — we do not store full card numbers), booking details (dates, site selected, party size), and basic technical information such as your IP address and browser type when you visit our site.

## 3. How We Use Your Information

We use your information to process and manage bookings; communicate with you about reservations, confirmations, and support requests; send booking-related emails such as check-in instructions and receipts; improve our listings and website experience; and comply with legal and accounting obligations.

## 4. Cookies & Tracking

We use cookies and similar technologies to keep you signed in, remember your preferences, and understand how our site is used. You can control cookies through your browser settings, though disabling them may affect some site functionality.

## 5. Third-Party Sharing

We share booking and contact information with the third-party services that power our operations — including our customer relationship and calendar platform (used to manage bookings and communications) and our payment processor (used to securely process payments). These providers only receive the information necessary to perform their function and are contractually required to protect it. We do not sell your personal information to third parties.

## 6. Data Retention

We retain booking and account information for as long as needed to provide the Service, fulfill the purposes described in this policy, and meet our legal and tax obligations. You may request deletion of your account information at any time, subject to records we're required to keep by law.

## 7. Your Rights

Depending on where you live, you may have the right to access, correct, or request deletion of your personal information, and to object to or restrict certain processing. To exercise these rights, contact us using the details on our Contact page.

## 8. Data Security

We use reasonable administrative, technical, and physical safeguards to protect your information. No method of transmission or storage is completely secure, so we cannot guarantee absolute security, but we work to protect your data appropriately for its sensitivity.

## 9. Children's Privacy

The Service is not directed to children under 13, and we do not knowingly collect personal information from children.

## 10. Changes to This Policy

We may update this Privacy Policy from time to time. Material changes will be reflected by an updated "Last updated" date above.

## 11. Contact Us

If you have questions about this Privacy Policy or how your information is handled, please reach out via our Contact page.
TEXT;
    }

    private function support(): string
    {
        return <<<'TEXT'
We're here to help with anything related to your booking, your account, or planning your stay.

## How Do I Book a Site?

Browse available rentals from the homepage, pick your dates, and follow the checkout steps. You'll receive a confirmation by email once your booking is finalized — some bookings are confirmed instantly, while others are briefly reviewed by our team first.

## Can I Change or Cancel My Booking?

Yes. Sign in to your account and open the booking from "My Bookings" to view your options, or contact us directly with your booking details and we'll help you make changes. Cancellation terms depend on the listing and how close to check-in you are — see our Terms of Service for details.

## How Do Payments Work?

Payments are processed securely at the time of booking or check-in, depending on the option shown during checkout. You can view receipts and invoices for any booking from your account at any time.

## I Didn't Get a Confirmation Email

Check your spam or promotions folder first. If it's still missing, sign in to "My Bookings" to confirm your reservation went through, or contact us with the name and email used for the booking and we'll resend it.

## How Do I Create or Access My Account?

An account is created automatically the first time you book as a guest — just use the same email to register a password afterward, or use "Forgot password" if you've booked before but never set one.

## Still Need Help?

Reach out through our Contact page with your name, booking reference (if you have one), and a description of what you need — we typically respond within one business day.
TEXT;
    }

    /**
     * Matches the site's actual current hardcoded header exactly — this is
     * the "apply what we have right now" starting point super-admin then
     * edits from, not a fresh design. See app/(customer)/layout.tsx's
     * previous hardcoded baseNavItems/colors for what these values mirror.
     */
    private function headerContent(): array
    {
        return [
            'logo_url' => null,
            'site_title' => [
                'primary_text' => 'Campground',
                'secondary_text' => 'Rentals',
                'primary_color' => '#1b1d21',
                'secondary_color' => '#135846',
            ],
            'menu_items' => [
                ['id' => 'site-map', 'label' => 'Site Map', 'href' => '/rentals/map', 'sort_order' => 1],
                ['id' => 'gallery', 'label' => 'Gallery', 'href' => '/gallery', 'sort_order' => 2],
                ['id' => 'about', 'label' => 'About', 'href' => '/about', 'sort_order' => 3],
                ['id' => 'contact', 'label' => 'Contact', 'href' => '/contact', 'sort_order' => 4],
            ],
            'layout' => [
                'logo_position' => 'left',
                'theme_toggle_position' => 'right',
                'login_order' => ['customer', 'staff'],
            ],
            'style' => [
                'background_type' => 'default',
                'background_color' => null,
                'gradient_from' => null,
                'gradient_to' => null,
                'gradient_direction' => 'to right',
                'background_image_url' => null,
                'hover_color' => '#135846',
            ],
        ];
    }

    /** Matches the site's actual current hardcoded footer exactly — see app/(customer)/layout.tsx's previous hardcoded footer JSX. */
    private function footerContent(): array
    {
        return [
            'logo_url' => null,
            'site_title' => [
                'primary_text' => 'Campground',
                'secondary_text' => 'Rentals',
                'primary_color' => '#ffffff',
                'secondary_color' => '#ffffff',
            ],
            'description' => 'Cabins, campsites and glamping stays — book your next escape in minutes and pay securely online.',
            'sections' => [
                'explore' => [
                    'title' => 'Explore',
                    'items' => [
                        ['id' => 'browse-rentals', 'label' => 'Browse Rentals', 'href' => '/', 'sort_order' => 1],
                        ['id' => 'gallery', 'label' => 'Gallery', 'href' => '/gallery', 'sort_order' => 2],
                        ['id' => 'about-us', 'label' => 'About Us', 'href' => '/about', 'sort_order' => 3],
                        ['id' => 'contact', 'label' => 'Contact', 'href' => '/contact', 'sort_order' => 4],
                    ],
                ],
                'legal' => [
                    'title' => 'Legal',
                    'items' => [
                        ['id' => 'terms', 'label' => 'Terms of Service', 'href' => '/terms-of-service', 'sort_order' => 1],
                        ['id' => 'privacy', 'label' => 'Privacy Policy', 'href' => '/privacy-policy', 'sort_order' => 2],
                        ['id' => 'support', 'label' => 'Support', 'href' => '/support', 'sort_order' => 3],
                    ],
                ],
            ],
            'contact_section_title' => 'Get in touch',
            'contact_fields_order' => ['address', 'phone', 'email'],
            'copyright_text' => '© {year} Campground Rentals. All bookings are subject to our cancellation policy.',
            'style' => [
                'background_type' => 'default',
                'background_color' => null,
                'gradient_from' => null,
                'gradient_to' => null,
                'gradient_direction' => 'to right',
                'background_image_url' => null,
                'hover_color' => '#ffffff',
            ],
        ];
    }

    private function aboutUs(): string
    {
        return <<<'TEXT'
Getting you outdoors, one campsite at a time.

## Our Story

We started this business with a simple goal: make it easy for families and adventurers to find and book the perfect outdoor getaway, without the hassle of phone calls and paperwork. From cozy tent sites to fully-equipped RV spots and cabins, our rentals are chosen and maintained to make your time outdoors comfortable and memorable.

## How We Work

Every listing on this site is managed by our small, dedicated team, who personally review each booking to make sure it's a great fit before confirming it. That's why some bookings go through a quick confirmation step — we want to make sure everything is ready for you before your trip.

## Our Commitment

We care about the land we operate on as much as we care about your stay. That means responsible upkeep of our sites, respect for the natural surroundings, and clear, honest communication before, during, and after your visit.

## Get in Touch

Have questions about a rental or need help planning your stay? Reach out any time on our Contact page — we're happy to help.
TEXT;
    }
}
