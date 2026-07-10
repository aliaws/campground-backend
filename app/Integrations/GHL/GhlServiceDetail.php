<?php

namespace App\Integrations\GHL;

/**
 * Typed, read-only view over a GHL calendar-service detail payload
 * (GET calendars/services/{id}). This is the ONLY shape live GHL rental data
 * flows through — raw payloads are never exposed to the frontend and never
 * persisted (the array lives in the short-lived server cache only).
 */
final readonly class GhlServiceDetail
{
    public function __construct(private array $raw) {}

    public function id(): ?string
    {
        return $this->raw['_id'] ?? $this->raw['id'] ?? null;
    }

    public function name(): ?string
    {
        return $this->raw['name'] ?? null;
    }

    public function variantName(): ?string
    {
        return $this->raw['variantName'] ?? null;
    }

    public function description(): ?string
    {
        return $this->raw['description'] ?? null;
    }

    public function slug(): ?string
    {
        return $this->raw['slug'] ?? null;
    }

    public function isActive(): bool
    {
        return ! empty($this->raw['isActive']) && empty($this->raw['deleted']);
    }

    public function coverImage(): ?string
    {
        return $this->raw['coverImage'] ?? null;
    }

    /** @return array<int, array{_id: ?string, url: ?string, name: string, position: int}> */
    public function images(): array
    {
        return collect($this->raw['images'] ?? [])->map(fn ($img, $i) => [
            '_id' => $img['_id'] ?? null,
            'url' => $img['url'] ?? null,
            'name' => $img['name'] ?? 'Image '.($i + 1),
            'position' => $img['position'] ?? $i,
        ])->values()->all();
    }

    /** null = unlimited stock. */
    public function quantity(): ?int
    {
        return isset($this->raw['quantity']) ? (int) $this->raw['quantity'] : null;
    }

    public function maxQuantity(): ?int
    {
        return isset($this->raw['maxQuantity']) ? (int) $this->raw['maxQuantity'] : null;
    }

    public function bookingUnit(): ?string
    {
        return $this->raw['bookingUnit'] ?? null;
    }

    public function minDuration(): ?int
    {
        return isset($this->raw['minDuration']) ? (int) $this->raw['minDuration'] : null;
    }

    public function maxDuration(): ?int
    {
        return isset($this->raw['maxDuration']) ? (int) $this->raw['maxDuration'] : null;
    }

    public function durationUnit(): ?string
    {
        return $this->raw['minDurationUnit'] ?? $this->raw['bookingUnit'] ?? null;
    }

    public function serviceDuration(): ?int
    {
        return isset($this->raw['serviceDuration']) ? (int) $this->raw['serviceDuration'] : null;
    }

    public function serviceDurationUnit(): ?string
    {
        return $this->raw['serviceDurationUnit'] ?? null;
    }

    public function bookingStartTime(): ?string
    {
        return $this->raw['bookingStartTime'] ?? null;
    }

    public function bookingEndTime(): ?string
    {
        return $this->raw['bookingEndTime'] ?? null;
    }

    public function bookingPeriodType(): ?string
    {
        return $this->raw['bookingPeriodType'] ?? null;
    }

    public function isVariantsEnabled(): bool
    {
        return (bool) ($this->raw['isVariantsEnabled'] ?? false);
    }

    /** Base listing's service id when this is a variant; null on base rows. */
    public function baseServiceId(): ?string
    {
        return $this->raw['variantId'] ?? null;
    }

    /** Payments-layer product auto-created by GHL for this service/variant. */
    public function paymentsProductId(): ?string
    {
        return $this->raw['productId'] ?? null;
    }

    public function serviceCategoryId(): ?string
    {
        return $this->raw['serviceCategoryId'] ?? null;
    }

    public function paymentAmount(): ?float
    {
        return isset($this->raw['payment']['amount']) ? (float) $this->raw['payment']['amount'] : null;
    }

    /** Embedded variant ids (base detail only; includes the base's own id sometimes). */
    public function embeddedVariantIds(): array
    {
        return collect($this->raw['variants'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * GHL pricingRule → the flat pricing-rule shape used everywhere internally
     * (quote calculator, resources, sync). Falls back to a bare per-day base
     * price from payment.amount when GHL has no pricingRule, or null when
     * there's no price signal at all.
     */
    public function pricingRule(): ?array
    {
        $rule = $this->raw['pricingRule'] ?? null;

        if (! $rule) {
            $amount = $this->paymentAmount();

            return $amount !== null
                ? ['base_price' => $amount, 'base_price_strategy' => 'per_day', 'rules' => []]
                : null;
        }

        return [
            'name' => $rule['name'] ?? null,
            'applies_to' => $rule['appliesTo'] ?? 'rental',
            'base_price' => (float) ($rule['basePrice']['value'] ?? $this->paymentAmount() ?? 0),
            'base_price_strategy' => $rule['basePrice']['strategy'] ?? 'per_day',
            'rules' => $rule['rules'] ?? [],
            'security_deposit_amount' => isset($rule['securityDeposit']['amount']) ? (float) $rule['securityDeposit']['amount'] : null,
            'security_deposit_refundable' => $rule['securityDeposit']['refundable'] ?? true,
            'payment_terms' => $rule['paymentTerms'] ?? null,
            'ghl_pricing_rule_id' => $rule['id'] ?? null,
        ];
    }

    public function basePrice(): ?float
    {
        $rule = $this->pricingRule();

        return isset($rule['base_price']) ? (float) $rule['base_price'] : null;
    }
}
