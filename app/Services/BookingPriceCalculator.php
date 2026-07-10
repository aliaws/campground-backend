<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Computes booking prices by applying a flat pricing-rule array (fetched live
 * from GHL via GhlServiceDetail::pricingRule()) the way GHL Rentals does:
 * a per-day base price, then rules in sequence order —
 *  - date_range         flat override (or % adjustment) of the nightly price
 *  - day_of_week        % adjustment (or flat override) of the nightly price
 *  - duration_discount  % / flat discount on the subtotal for long stays
 *  - quantity_discount  % / flat discount on the subtotal for multi-unit bookings
 */
class BookingPriceCalculator
{
    /**
     * @param  ?array  $rule  flat pricing-rule shape: base_price, rules[],
     *                        security_deposit_amount, payment_terms
     * @return array{
     *   nights: int, quantity: int, base_price: float,
     *   nightly: array, subtotal: float, discounts: array, discount_amount: float,
     *   total_amount: float, security_deposit_amount: float, grand_total: float,
     *   payment_terms: ?array
     * }
     */
    public function quote(?array $rule, string $checkIn, string $checkOut, int $quantity = 1): array
    {
        $checkInDate = CarbonImmutable::parse($checkIn)->startOfDay();
        $checkOutDate = CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = max((int) $checkInDate->diffInDays($checkOutDate), 1);

        $basePrice = (float) ($rule['base_price'] ?? 0);
        $rules = $this->orderedRules($rule);

        // ── Nightly prices ────────────────────────────────────────────────────
        $nightly = [];
        for ($date = $checkInDate; $date->lt($checkOutDate); $date = $date->addDay()) {
            $price = $basePrice;
            $applied = [];

            foreach ($rules as $r) {
                $adjusted = $this->applyNightlyRule($r, $date, $price);

                if ($adjusted !== null) {
                    $price = $adjusted;
                    $applied[] = $r['type'];
                }
            }

            $nightly[] = [
                'date' => $date->toDateString(),
                'amount' => round($price, 2),
                'applied_rules' => $applied,
            ];
        }

        $subtotal = round(array_sum(array_column($nightly, 'amount')) * $quantity, 2);

        // ── Subtotal discounts ────────────────────────────────────────────────
        $discounts = [];
        $discountAmount = 0.0;

        foreach ($rules as $r) {
            $discount = $this->applyDiscountRule($r, $subtotal, $nights, $quantity);

            if ($discount !== null) {
                $discounts[] = [
                    'type' => $r['type'],
                    'value' => $r['value'],
                    'value_type' => $r['valueType'] ?? 'percentage',
                    'amount' => $discount,
                ];
                $discountAmount += $discount;
            }
        }

        $discountAmount = round(min($discountAmount, $subtotal), 2);
        $total = round($subtotal - $discountAmount, 2);
        $deposit = round((float) ($rule['security_deposit_amount'] ?? 0), 2);

        return [
            'nights' => $nights,
            'quantity' => $quantity,
            'base_price' => round($basePrice, 2),
            'nightly' => $nightly,
            'subtotal' => $subtotal,
            'discounts' => $discounts,
            'discount_amount' => $discountAmount,
            'total_amount' => $total,
            'security_deposit_amount' => $deposit,
            'grand_total' => round($total + $deposit, 2),
            'payment_terms' => $rule['payment_terms'] ?? null,
        ];
    }

    /** Nightly price after a date_range / day_of_week rule, or null if it doesn't match. */
    private function applyNightlyRule(array $rule, CarbonImmutable $date, float $price): ?float
    {
        $match = $rule['match'] ?? [];
        $valueType = $rule['valueType'] ?? 'flat';
        $value = (float) ($rule['value'] ?? 0);

        $matches = match ($rule['type'] ?? null) {
            'date_range' => isset($match['from'], $match['to'])
                && $date->betweenIncluded(
                    CarbonImmutable::parse($match['from'])->startOfDay(),
                    CarbonImmutable::parse($match['to'])->startOfDay()
                ),
            'day_of_week' => isset($match['dayOfWeek']) && $date->dayOfWeek === (int) $match['dayOfWeek'],
            default => false,
        };

        if (! $matches) {
            return null;
        }

        return $valueType === 'flat'
            ? $value
            : $price + ($price * $value / 100);
    }

    /** Discount amount from a duration/quantity rule, or null if it doesn't apply. */
    private function applyDiscountRule(array $rule, float $subtotal, int $nights, int $quantity): ?float
    {
        $match = $rule['match'] ?? [];
        $valueType = $rule['valueType'] ?? 'percentage';
        $value = (float) ($rule['value'] ?? 0);

        $applies = match ($rule['type'] ?? null) {
            'duration_discount' => isset($match['duration']) && $nights >= (int) $match['duration'],
            'quantity_discount' => isset($match['min']) && $quantity >= (int) $match['min'],
            default => false,
        };

        if (! $applies) {
            return null;
        }

        return round($valueType === 'flat' ? $value : $subtotal * $value / 100, 2);
    }

    /** The rule list ordered by sequence, ready to apply. */
    private function orderedRules(?array $rule): array
    {
        $rules = $rule['rules'] ?? [];

        usort($rules, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

        return $rules;
    }
}
