<?php

namespace App\Services\Billing;

use App\Models\PricingPlan;
use App\Support\Billing\UsageMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estimated monthly bill for one organization. Decimal-safe (brick/math,
 * never floats). Per metric:
 *
 *   extra = max(0, usage - included) / billing_unit × unit_price × metric_factor
 *
 * rounded half-up to 2 decimals. Then
 *
 *   subtotal = base_price + Σ rounded extras
 *   total    = round(subtotal × organization pricing factor, 2)
 *
 * so the displayed lines always add up to the displayed subtotal, and the
 * displayed adjustment is exactly total - subtotal. Usage is proportional
 * (not rounded up to whole billing blocks).
 *
 * A metric whose usage is null (not measured) is reported as "missing",
 * charged nothing, and makes the estimate incomplete — never treated as 0.
 */
class BillingCalculator
{
    /** Internal precision for intermediate division. */
    private const SCALE = 12;

    /**
     * @param  array<string, string|int|float|null>  $usage  metric key => quantity in the metric's unit (null = unmeasured)
     */
    public function calculate(PricingPlan $plan, array $usage, string|int|float $organizationFactor = '1'): array
    {
        $configs = $plan->metricConfigs();
        $lines = [];
        $extrasSum = BigDecimal::zero();
        $missing = [];

        foreach (UsageMetrics::all() as $key => $metric) {
            $config = $configs[$key];
            $raw = $usage[$key] ?? null;

            $line = [
                'key' => $key,
                'label' => $metric['label'],
                'unit' => $metric['unit'],
                'included' => $this->fmt(BigDecimal::of($config['included_quantity']), 4),
                'billing_unit' => $this->fmt(BigDecimal::of($config['billing_unit']), 4),
                'unit_price' => $this->fmt(BigDecimal::of($config['unit_price']), 4),
                'metric_factor' => $this->fmt(BigDecimal::of($config['metric_factor']), 4),
                'usage' => null,
                'excess' => null,
                'line_total' => '0.00',
                'status' => 'missing',
            ];

            if ($raw === null || $raw === '') {
                $missing[] = $key;
                $lines[] = $line;

                continue;
            }

            $quantity = BigDecimal::of((string) $raw);
            $excess = $quantity->minus($config['included_quantity']);
            if ($excess->isNegative()) {
                $excess = BigDecimal::zero();
            }

            $charge = $excess
                ->dividedBy($config['billing_unit'], self::SCALE, RoundingMode::HalfUp)
                ->multipliedBy($config['unit_price'])
                ->multipliedBy($config['metric_factor'])
                ->toScale(2, RoundingMode::HalfUp);

            $extrasSum = $extrasSum->plus($charge);

            $line['usage'] = $this->fmt($quantity, $metric['decimals']);
            $line['excess'] = $this->fmt($excess, $metric['decimals']);
            $line['line_total'] = (string) $charge;
            $line['status'] = 'measured';
            $lines[] = $line;
        }

        $base = BigDecimal::of((string) $plan->base_price)->toScale(2, RoundingMode::HalfUp);
        $subtotal = $base->plus($extrasSum)->toScale(2);
        $factor = BigDecimal::of((string) $organizationFactor);
        $total = $subtotal->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp);

        return [
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'is_default' => (bool) $plan->is_default],
            'currency' => $plan->currency,
            'base_price' => (string) $base,
            'lines' => $lines,
            'usage_charges' => (string) $extrasSum->toScale(2),
            'subtotal' => (string) $subtotal,
            'pricing_factor' => $this->fmt($factor, 4),
            'adjustment' => (string) $total->minus($subtotal),
            'total' => (string) $total,
            'complete' => $missing === [],
            'missing_metrics' => $missing,
        ];
    }

    /** Decimal string with at most $decimals places, trailing zeros removed. */
    private function fmt(BigDecimal $value, int $decimals): string
    {
        $text = (string) $value->toScale($decimals, RoundingMode::HalfUp);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
