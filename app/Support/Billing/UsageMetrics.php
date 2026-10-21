<?php

namespace App\Support\Billing;

/**
 * The single list of usage metrics the platform measures and can bill for.
 * Used by the snapshot command, the monthly aggregation, the billing
 * calculator, the superadmin API (GET /superadmin/billing/metrics, which the
 * frontend reads instead of keeping its own copy) and the pricing PDF.
 *
 * Keys are stored in pricing_plan_metrics.metric_key and in every daily
 * snapshot's `metrics` JSON. NEVER rename or reuse a key: add a new one and
 * stop measuring the old one instead. See docs/usage-billing.md.
 *
 * `active_users` is deliberately absent: the app records no reliable
 * per-user activity (no last-login or activity log column), so it can't be
 * measured honestly.
 */
final class UsageMetrics
{
    public const MEMBERS = 'members';

    public const CUSTOMERS = 'customers';

    public const BOOKINGS = 'bookings';

    public const PRODUCTS = 'products';

    public const RENTAL_UNITS = 'rental_units';

    public const TRANSACTIONS = 'transactions';

    public const FILE_STORAGE = 'file_storage';

    public const DATABASE_STORAGE = 'database_storage';

    /** Monthly aggregation of daily snapshots. */
    public const AGGREGATE_AVERAGE = 'average';

    public const AGGREGATE_MAX = 'max';

    public const AGGREGATE_LATEST = 'latest';

    /** 1 GB as used for billing and display (binary: 1024³ bytes). */
    public const BYTES_PER_GB = 1073741824;

    /** Allowed range for a plan's per-metric factor and an organization's pricing factor. */
    public const FACTOR_MIN = 0;

    public const FACTOR_MAX = 10;

    /**
     * @return array<string, array{key: string, label: string, unit: string, unit_plural: string, default_billing_unit: string, aggregation: string, decimals: int, estimated: bool, help: string, source: string}>
     */
    public static function all(): array
    {
        $metrics = [
            self::MEMBERS => [
                'label' => 'Members',
                'unit' => 'member',
                'unit_plural' => 'members',
                'default_billing_unit' => '1',
                'aggregation' => self::AGGREGATE_MAX,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'Staff accounts (owner, admin or staff role) linked to the organization. Billed on the highest daily count in the month.',
                'source' => 'engage_users_locations joined to engage_users (roles contain owner, admin or staff).',
            ],
            self::CUSTOMERS => [
                'label' => 'Customers',
                'unit' => 'customer',
                'unit_plural' => 'customers',
                'default_billing_unit' => '100',
                'aggregation' => self::AGGREGATE_LATEST,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'Customer records linked to the organization (archived customers excluded). Latest count in the month.',
                'source' => 'engage_customers_locations joined to engage_customers where deleted_at is null.',
            ],
            self::BOOKINGS => [
                'label' => 'Bookings',
                'unit' => 'booking',
                'unit_plural' => 'bookings',
                'default_billing_unit' => '100',
                'aggregation' => self::AGGREGATE_LATEST,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'All bookings stored for the organization, any status. Latest count in the month.',
                'source' => 'engage_bookings.engage_organization_location_id.',
            ],
            self::PRODUCTS => [
                'label' => 'Products',
                'unit' => 'product',
                'unit_plural' => 'products',
                'default_billing_unit' => '50',
                'aggregation' => self::AGGREGATE_LATEST,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'Store (non-rental) products, deleted products excluded. Latest count in the month.',
                'source' => 'engage_products where product_rental_id is null and deleted_at is null.',
            ],
            self::RENTAL_UNITS => [
                'label' => 'Rental units',
                'unit' => 'rental unit',
                'unit_plural' => 'rental units',
                'default_billing_unit' => '10',
                'aggregation' => self::AGGREGATE_LATEST,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'Active bookable campsite/rental rows (each listing and each of its variants). Latest count in the month.',
                'source' => 'engage_product_rentals (is_active) under engage_products that are not deleted.',
            ],
            self::TRANSACTIONS => [
                'label' => 'Transactions',
                'unit' => 'transaction',
                'unit_plural' => 'transactions',
                'default_billing_unit' => '100',
                'aggregation' => self::AGGREGATE_LATEST,
                'decimals' => 0,
                'estimated' => false,
                'help' => 'Campsite transactions plus product transactions stored for the organization (archived campsite transactions excluded). Latest count in the month.',
                'source' => 'engage_rental_transactions + engage_product_transactions.',
            ],
            self::FILE_STORAGE => [
                'label' => 'File storage',
                'unit' => 'GB',
                'unit_plural' => 'GB',
                'default_billing_unit' => '1',
                'aggregation' => self::AGGREGATE_AVERAGE,
                'decimals' => 3,
                'estimated' => false,
                'help' => 'Uploaded files owned by the organization (product and campsite photos, category images, site map photos and icons, profile pictures). Average of the daily measurements in the month.',
                'source' => 'Application storage disks scanned with filesystem metadata; ownership from database file references.',
            ],
            self::DATABASE_STORAGE => [
                'label' => 'Database storage',
                'unit' => 'GB',
                'unit_plural' => 'GB',
                'default_billing_unit' => '1',
                'aggregation' => self::AGGREGATE_AVERAGE,
                'decimals' => 4,
                'estimated' => true,
                'help' => 'Estimated PostgreSQL space used by the organization\'s rows, including a proportional share of indexes. Average of the daily measurements in the month.',
                'source' => 'pg_column_size of the organization\'s rows, scaled by pg_total_relation_size of each table.',
            ],
        ];

        foreach ($metrics as $key => $definition) {
            $metrics[$key] = ['key' => $key] + $definition;
        }

        return $metrics;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown usage metric [{$key}].");
    }

    /** The configuration a plan uses for a metric it has no stored row for. */
    public static function defaultConfig(string $key): array
    {
        return [
            'included_quantity' => '0',
            'unit_price' => '0',
            'billing_unit' => self::get($key)['default_billing_unit'],
            'metric_factor' => '1',
        ];
    }

    /** @return list<string> */
    public static function storageKeys(): array
    {
        return [self::FILE_STORAGE, self::DATABASE_STORAGE];
    }
}
