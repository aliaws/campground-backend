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

    /**
     * The full images array to persist into `engage_products.images` —
     * `images()` as-is when GHL returned one, or a synthesized single-entry
     * array from the legacy `coverImage` field when it didn't (a service
     * with zero images in the array, or a payload shape without one at
     * all). `images` is the only column write sites need to set — `image`
     * on `EngageProduct` is a computed accessor derived from this array's
     * position:0 entry, see `EngageProduct::image()` — so this single
     * method covers both the "full gallery" and "single cover image" cases
     * a caller ever needs from a service detail.
     *
     * @return array<int, array{_id: ?string, url: ?string, name: string, position: int}>
     */
    public function imagesForPersistence(): array
    {
        $images = $this->images();

        if ($images !== []) {
            return $images;
        }

        $cover = $this->coverImage();

        return $cover ? [['_id' => null, 'url' => $cover, 'name' => $this->name() ?? 'Image 1', 'position' => 0]] : [];
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

    /**
     * Booking Settings tab (2026-08-21) — one raw GHL key per accessor,
     * following this class's own established pattern above. Unlike
     * durationUnit() above (a pre-existing, differently-named fallback used
     * elsewhere), these two read minDurationUnit/maxDurationUnit directly,
     * since the Booking Settings tab needs them as two independent values,
     * not one shared fallback.
     */
    public function minDurationUnit(): ?string
    {
        return $this->raw['minDurationUnit'] ?? null;
    }

    public function maxDurationUnit(): ?string
    {
        return $this->raw['maxDurationUnit'] ?? null;
    }

    public function hasTimeSelection(): bool
    {
        return (bool) ($this->raw['hasTimeSelection'] ?? false);
    }

    public function preBuffer(): ?int
    {
        return isset($this->raw['preBuffer']) ? (int) $this->raw['preBuffer'] : null;
    }

    public function preBufferUnit(): ?string
    {
        return $this->raw['preBufferUnit'] ?? null;
    }

    public function postBuffer(): ?int
    {
        return isset($this->raw['postBuffer']) ? (int) $this->raw['postBuffer'] : null;
    }

    public function postBufferUnit(): ?string
    {
        return $this->raw['postBufferUnit'] ?? null;
    }

    public function allowBookingAfter(): ?int
    {
        return isset($this->raw['allowBookingAfter']) ? (int) $this->raw['allowBookingAfter'] : null;
    }

    public function allowBookingAfterUnit(): ?string
    {
        return $this->raw['allowBookingAfterUnit'] ?? null;
    }

    public function allowBookingFor(): ?int
    {
        return isset($this->raw['allowBookingFor']) ? (int) $this->raw['allowBookingFor'] : null;
    }

    public function allowBookingForUnit(): ?string
    {
        return $this->raw['allowBookingForUnit'] ?? null;
    }

    /** Fixed-duration intervals ("2 day", "1 week", ...) — only meaningful when bookingPeriodType() === 'fixed'. */
    public function serviceDurations(): array
    {
        return collect($this->raw['serviceDurations'] ?? [])->map(fn ($d) => [
            'duration' => isset($d['duration']) ? (int) $d['duration'] : null,
            'durationUnit' => $d['durationUnit'] ?? null,
        ])->values()->all();
    }

    /**
     * The full booking-period configuration to persist onto
     * engage_product_rentals.booking_settings — one JSON object mirroring
     * GHL's own raw field names verbatim (camelCase, not translated to
     * snake_case) so nothing is lost or renamed on the way in, and so a
     * later read-back can be compared directly against a fresh GHL payload.
     * bookingPeriodType itself is stored separately (its own column, since
     * it drives which fields the edit form shows) — deliberately not
     * repeated inside this object.
     *
     * @return array{minDuration: ?int, minDurationUnit: ?string, maxDuration: ?int, maxDurationUnit: ?string, preBuffer: ?int, preBufferUnit: ?string, postBuffer: ?int, postBufferUnit: ?string, allowBookingAfter: ?int, allowBookingAfterUnit: ?string, allowBookingFor: ?int, allowBookingForUnit: ?string, hasTimeSelection: bool, bookingStartTime: ?string, bookingEndTime: ?string, serviceDurations: array}
     */
    public function bookingSettingsForPersistence(): array
    {
        return [
            'minDuration' => $this->minDuration(),
            'minDurationUnit' => $this->minDurationUnit(),
            'maxDuration' => $this->maxDuration(),
            'maxDurationUnit' => $this->maxDurationUnit(),
            'preBuffer' => $this->preBuffer(),
            'preBufferUnit' => $this->preBufferUnit(),
            'postBuffer' => $this->postBuffer(),
            'postBufferUnit' => $this->postBufferUnit(),
            'allowBookingAfter' => $this->allowBookingAfter(),
            'allowBookingAfterUnit' => $this->allowBookingAfterUnit(),
            'allowBookingFor' => $this->allowBookingFor(),
            'allowBookingForUnit' => $this->allowBookingForUnit(),
            'hasTimeSelection' => $this->hasTimeSelection(),
            'bookingStartTime' => $this->bookingStartTime(),
            'bookingEndTime' => $this->bookingEndTime(),
            'serviceDurations' => $this->serviceDurations(),
        ];
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
