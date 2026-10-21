<?php

namespace Tests\Unit\Billing;

use App\Models\PricingPlan;
use App\Models\PricingPlanMetric;
use App\Services\Billing\BillingCalculator;
use App\Support\Billing\UsageMetrics;
use Tests\TestCase;

class BillingCalculatorTest extends TestCase
{
    private function plan(string $basePrice, array $metrics): PricingPlan
    {
        $plan = new PricingPlan(['name' => 'Test', 'currency' => 'USD', 'base_price' => $basePrice]);
        $plan->setRelation('metrics', collect($metrics)->map(fn ($m, $key) => new PricingPlanMetric($m + ['metric_key' => $key]))->values());

        return $plan;
    }

    private function usage(array $overrides): array
    {
        return array_merge(array_fill_keys(UsageMetrics::keys(), '0'), $overrides);
    }

    public function test_charges_only_usage_above_the_allowance_per_billing_unit(): void
    {
        $plan = $this->plan('49.00', [
            'bookings' => ['included_quantity' => 100, 'unit_price' => 5, 'billing_unit' => 100, 'metric_factor' => 1],
            'file_storage' => ['included_quantity' => 2, 'unit_price' => '0.50', 'billing_unit' => 1, 'metric_factor' => 1],
        ]);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['bookings' => 350, 'file_storage' => '1.5']));
        $lines = collect($bill['lines'])->keyBy('key');

        $this->assertSame('12.50', $lines['bookings']['line_total']); // 250 / 100 × 5
        $this->assertSame('250', $lines['bookings']['excess']);
        $this->assertSame('0.00', $lines['file_storage']['line_total']); // under the allowance
        $this->assertSame('0', $lines['file_storage']['excess']);
        $this->assertSame('61.50', $bill['subtotal']);
        $this->assertSame('61.50', $bill['total']);
        $this->assertTrue($bill['complete']);
    }

    public function test_rounds_each_line_half_up_and_subtotal_is_the_sum_of_rounded_lines(): void
    {
        $plan = $this->plan('10.00', [
            'customers' => ['included_quantity' => 0, 'unit_price' => '0.015', 'billing_unit' => 1, 'metric_factor' => 1],
            'products' => ['included_quantity' => 0, 'unit_price' => '0.015', 'billing_unit' => 1, 'metric_factor' => 1],
        ]);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['customers' => 1, 'products' => 1]));
        $lines = collect($bill['lines'])->keyBy('key');

        // 0.015 rounds half-up to 0.02 on each line; 10 + 0.02 + 0.02.
        $this->assertSame('0.02', $lines['customers']['line_total']);
        $this->assertSame('0.02', $lines['products']['line_total']);
        $this->assertSame('10.04', $bill['subtotal']);
        $sum = collect($bill['lines'])->sum(fn ($line) => (int) round((float) $line['line_total'] * 100));
        $this->assertSame((int) round((float) $bill['subtotal'] * 100), 1000 + $sum);
    }

    public function test_applies_metric_factor_and_organization_pricing_factor(): void
    {
        $plan = $this->plan('100.00', [
            'members' => ['included_quantity' => 5, 'unit_price' => 10, 'billing_unit' => 1, 'metric_factor' => '1.5'],
        ]);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['members' => 7]), '0.9');
        $lines = collect($bill['lines'])->keyBy('key');

        $this->assertSame('30.00', $lines['members']['line_total']); // 2 × 10 × 1.5
        $this->assertSame('130.00', $bill['subtotal']);
        $this->assertSame('117.00', $bill['total']);
        $this->assertSame('-13.00', $bill['adjustment']);
        $this->assertSame('0.9', $bill['pricing_factor']);
    }

    public function test_total_rounds_half_up_after_the_factor(): void
    {
        $plan = $this->plan('0.05', []);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage([]), '0.5'); // 0.025

        $this->assertSame('0.03', $bill['total']);
    }

    public function test_missing_usage_is_reported_never_charged_as_zero_usage(): void
    {
        $plan = $this->plan('20.00', [
            'database_storage' => ['included_quantity' => 0, 'unit_price' => 100, 'billing_unit' => 1, 'metric_factor' => 1],
        ]);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['database_storage' => null]));
        $line = collect($bill['lines'])->firstWhere('key', 'database_storage');

        $this->assertSame('missing', $line['status']);
        $this->assertNull($line['usage']);
        $this->assertFalse($bill['complete']);
        $this->assertSame(['database_storage'], $bill['missing_metrics']);
    }

    public function test_metric_without_a_plan_row_uses_safe_defaults(): void
    {
        $plan = $this->plan('5.00', []);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['transactions' => 100000]));
        $line = collect($bill['lines'])->firstWhere('key', 'transactions');

        $this->assertSame('0.00', $line['line_total']);
        $this->assertSame(UsageMetrics::get('transactions')['default_billing_unit'], $line['billing_unit']);
        $this->assertSame('5.00', $bill['total']);
    }

    public function test_large_values_stay_exact(): void
    {
        $plan = $this->plan('99999999.99', [
            'transactions' => ['included_quantity' => 0, 'unit_price' => '0.0001', 'billing_unit' => 1, 'metric_factor' => 1],
        ]);

        $bill = (new BillingCalculator)->calculate($plan, $this->usage(['transactions' => '123456789012']));

        $this->assertSame('12345678.90', collect($bill['lines'])->firstWhere('key', 'transactions')['line_total']);
        $this->assertSame('112345678.89', $bill['total']);
    }
}
