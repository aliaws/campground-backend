<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * - Truncate products/rentals/bookings/transactions/site-maps data
 * - Reshape + seed countries
 * - Drop sessions (unused — this app uses JWT/token auth, not session auth)
 *
 * **2026-08-12 merge revision**: this migration originally also created an
 * `orders` table + morphed `transactions` to Booking|Order, dropped
 * `bookings.ghl_invoice_*`, dropped `custom_fields`, and renamed
 * `service_amenities`/`service_features` to `product_rental_*`. All four
 * were reverted while merging `main` in — `main` independently replaced
 * `transactions`/Order with `RentalTransaction`/`ProductTransaction`
 * (adopted instead, see 2026_08_10_000007_drop_transactions_and_transaction_items_tables.php),
 * still actively uses `bookings.ghl_invoice_*` (the whole payment-link/QR
 * flow depends on it) and the `custom_fields` feature, and
 * `service_amenities`/`service_features` (keyed by `product_id`) is
 * main's actively-developed Amenities/Features system. Keeping this
 * migration's own truncate/countries work, which doesn't collide with any
 * of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->truncateOperationalData();
        $this->reshapeAndSeedCountries();
        Schema::dropIfExists('sessions');
    }

    public function down(): void
    {
        // Irreversible data truncate + structural pivot; restore from backup.
        throw new RuntimeException('This migration cannot be reversed safely.');
    }

    private function truncateOperationalData(): void
    {
        // FK-safe wipe of the tables the user listed (+ site map stack).
        // transaction_items/transactions may already be gone on a database
        // that picked up the later 2026-08-10 consolidation migration
        // (which drops them) before this one ever got to run — only
        // truncate whichever of these actually still exist.
        $candidates = [
            'transaction_items',
            'transactions',
            'bookings',
            'site_map_elements',
            'site_maps',
            'site_map_icon_types',
            'product_categories',
            'service_amenities',
            'service_features',
            'product_rentals',
            'products',
        ];

        $existing = array_filter($candidates, fn ($table) => Schema::hasTable($table));

        if (! empty($existing)) {
            DB::statement('TRUNCATE TABLE '.implode(', ', $existing).' RESTART IDENTITY CASCADE');
        }
    }

    private function reshapeAndSeedCountries(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'code')) {
                $table->dropColumn('code');
            }
        });

        Schema::table('countries', function (Blueprint $table) {
            if (! Schema::hasColumn('countries', 'flag_emoji')) {
                $table->string('flag_emoji', 16)->nullable()->after('name');
            }
            if (! Schema::hasColumn('countries', 'iso2')) {
                $table->string('iso2', 2)->nullable()->after('flag_emoji');
            }
            if (! Schema::hasColumn('countries', 'dial_code')) {
                $table->string('dial_code', 16)->nullable()->after('iso2');
            }
            if (! Schema::hasColumn('countries', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('dial_code');
            }
            if (! Schema::hasColumn('countries', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->unique('iso2');
        });

        DB::table('countries')->delete();

        $now = now();
        $rows = [];
        foreach ($this->countries() as $i => $country) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'name' => $country['name'],
                'flag_emoji' => $country['flag_emoji'],
                'iso2' => $country['iso2'],
                'dial_code' => $country['dial_code'],
                'is_active' => true,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('countries')->insert($rows);
    }

    /** @return list<array{name:string,iso2:string,dial_code:string,flag_emoji:string}> */
    private function countries(): array
    {
        return [
            ['name' => 'United States', 'iso2' => 'US', 'dial_code' => '+1', 'flag_emoji' => '🇺🇸'],
            ['name' => 'Canada', 'iso2' => 'CA', 'dial_code' => '+1', 'flag_emoji' => '🇨🇦'],
            ['name' => 'United Kingdom', 'iso2' => 'GB', 'dial_code' => '+44', 'flag_emoji' => '🇬🇧'],
            ['name' => 'Germany', 'iso2' => 'DE', 'dial_code' => '+49', 'flag_emoji' => '🇩🇪'],
            ['name' => 'France', 'iso2' => 'FR', 'dial_code' => '+33', 'flag_emoji' => '🇫🇷'],
            ['name' => 'Italy', 'iso2' => 'IT', 'dial_code' => '+39', 'flag_emoji' => '🇮🇹'],
            ['name' => 'Spain', 'iso2' => 'ES', 'dial_code' => '+34', 'flag_emoji' => '🇪🇸'],
            ['name' => 'Netherlands', 'iso2' => 'NL', 'dial_code' => '+31', 'flag_emoji' => '🇳🇱'],
            ['name' => 'Switzerland', 'iso2' => 'CH', 'dial_code' => '+41', 'flag_emoji' => '🇨🇭'],
            ['name' => 'Sweden', 'iso2' => 'SE', 'dial_code' => '+46', 'flag_emoji' => '🇸🇪'],
            ['name' => 'Norway', 'iso2' => 'NO', 'dial_code' => '+47', 'flag_emoji' => '🇳🇴'],
            ['name' => 'Denmark', 'iso2' => 'DK', 'dial_code' => '+45', 'flag_emoji' => '🇩🇰'],
            ['name' => 'Pakistan', 'iso2' => 'PK', 'dial_code' => '+92', 'flag_emoji' => '🇵🇰'],
            ['name' => 'India', 'iso2' => 'IN', 'dial_code' => '+91', 'flag_emoji' => '🇮🇳'],
            ['name' => 'Australia', 'iso2' => 'AU', 'dial_code' => '+61', 'flag_emoji' => '🇦🇺'],
            ['name' => 'Mexico', 'iso2' => 'MX', 'dial_code' => '+52', 'flag_emoji' => '🇲🇽'],
            ['name' => 'United Arab Emirates', 'iso2' => 'AE', 'dial_code' => '+971', 'flag_emoji' => '🇦🇪'],
            ['name' => 'Singapore', 'iso2' => 'SG', 'dial_code' => '+65', 'flag_emoji' => '🇸🇬'],
            ['name' => 'Japan', 'iso2' => 'JP', 'dial_code' => '+81', 'flag_emoji' => '🇯🇵'],
            ['name' => 'China', 'iso2' => 'CN', 'dial_code' => '+86', 'flag_emoji' => '🇨🇳'],
        ];
    }
};
