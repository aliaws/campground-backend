<?php

namespace Database\Seeders;

use App\Models\PricingPlan;
use App\Services\Billing\PricingPlanService;
use App\Support\Billing\UsageMetrics;
use Illuminate\Database\Seeder;

/**
 * Starting pricing plans for a campground management platform (USD/month):
 *
 *   Standard  $49   small campground: a few staff, up to 25 rental units
 *   Growth    $129  mid-size park: up to 100 rental units, more staff
 *   Resort    $299  large resort / multi-site operation: up to 400 units
 *
 * Each plan includes an allowance per metric; usage above it is charged per
 * billing unit (e.g. $15 per 10 extra rental units on Standard).
 *
 * Safe to run more than once and never overwrites a superadmin's edits:
 * - Standard (created by the 2026_10_21_000001 migration) is filled in only
 *   while it is still untouched (base price 0 and no metric prices);
 * - Growth and Resort are created only if no plan with that name exists.
 *
 *   php artisan db:seed --class=PricingPlanSeeder
 */
class PricingPlanSeeder extends Seeder
{
    public function run(PricingPlanService $plans): void
    {
        foreach ($this->plans() as $sortOrder => $definition) {
            $metrics = $this->metricRows($definition['metrics']);
            $attributes = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'currency' => 'USD',
                'base_price' => $definition['base_price'],
                'is_active' => true,
                'sort_order' => $sortOrder,
                'metrics' => $metrics,
            ];

            $existing = PricingPlan::query()->where('name', $definition['name'])->first();

            if ($existing === null) {
                $plans->create($attributes);
                $this->command?->info("Created plan {$definition['name']}.");

                continue;
            }

            $untouched = (float) $existing->base_price === 0.0 && ! $existing->metrics()->exists();
            if ($untouched) {
                $plans->update($existing, $attributes);
                $this->command?->info("Filled in plan {$definition['name']}.");
            } else {
                $this->command?->warn("Plan {$definition['name']} already has prices; left unchanged.");
            }
        }
    }

    /**
     * Per metric: [included, unit price, billing unit]. Metric factor is 1.
     *
     * @return list<array{name: string, description: string, base_price: string, metrics: array<string, array{0: int|string, 1: string, 2: int|string}>}>
     */
    private function plans(): array
    {
        return [
            [
                'name' => 'Standard',
                'description' => 'For small campgrounds and RV parks: up to 3 staff and 25 campsites included.',
                'base_price' => '49.00',
                'metrics' => [
                    UsageMetrics::MEMBERS => [3, '10.00', 1],
                    UsageMetrics::CUSTOMERS => [500, '5.00', 100],
                    UsageMetrics::BOOKINGS => [200, '8.00', 100],
                    UsageMetrics::PRODUCTS => [100, '5.00', 50],
                    UsageMetrics::RENTAL_UNITS => [25, '15.00', 10],
                    UsageMetrics::TRANSACTIONS => [500, '5.00', 100],
                    UsageMetrics::FILE_STORAGE => [5, '0.50', 1],
                    UsageMetrics::DATABASE_STORAGE => [1, '2.00', 1],
                ],
            ],
            [
                'name' => 'Growth',
                'description' => 'For busy parks with a camp store: up to 10 staff and 100 campsites included, lower overage prices.',
                'base_price' => '129.00',
                'metrics' => [
                    UsageMetrics::MEMBERS => [10, '8.00', 1],
                    UsageMetrics::CUSTOMERS => [2500, '4.00', 100],
                    UsageMetrics::BOOKINGS => [1000, '6.00', 100],
                    UsageMetrics::PRODUCTS => [300, '4.00', 50],
                    UsageMetrics::RENTAL_UNITS => [100, '12.00', 10],
                    UsageMetrics::TRANSACTIONS => [2500, '4.00', 100],
                    UsageMetrics::FILE_STORAGE => [25, '0.40', 1],
                    UsageMetrics::DATABASE_STORAGE => [5, '1.50', 1],
                ],
            ],
            [
                'name' => 'Resort',
                'description' => 'For large resorts and multi-site operations: up to 30 staff and 400 campsites included, the lowest overage prices.',
                'base_price' => '299.00',
                'metrics' => [
                    UsageMetrics::MEMBERS => [30, '6.00', 1],
                    UsageMetrics::CUSTOMERS => [10000, '3.00', 100],
                    UsageMetrics::BOOKINGS => [5000, '4.00', 100],
                    UsageMetrics::PRODUCTS => [1000, '3.00', 50],
                    UsageMetrics::RENTAL_UNITS => [400, '10.00', 10],
                    UsageMetrics::TRANSACTIONS => [10000, '3.00', 100],
                    UsageMetrics::FILE_STORAGE => [100, '0.30', 1],
                    UsageMetrics::DATABASE_STORAGE => [20, '1.00', 1],
                ],
            ],
        ];
    }

    private function metricRows(array $metrics): array
    {
        $rows = [];
        foreach ($metrics as $key => [$included, $price, $unit]) {
            $rows[] = [
                'key' => $key,
                'included_quantity' => (string) $included,
                'unit_price' => $price,
                'billing_unit' => (string) $unit,
                'metric_factor' => '1',
            ];
        }

        return $rows;
    }
}
