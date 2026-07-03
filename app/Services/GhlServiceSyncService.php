<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Pulls GHL Calendar Rentals into the unified products table.
 *
 * GHL rental mental model (scheduling vs payments):
 * - Scheduling layer: GET calendars/services?industryType=rental
 *   Each listing AND each variant is its own service record (_id → ghl_service_id).
 * - Payments layer: every service/variant auto-creates a Product (productId → engage_product_id).
 * - The service *catalog* API (calendars/services/catalog) is for classic Services v2
 *   bookings and is often empty for rental accounts — do NOT use it for sync or listing.
 *
 * Sync strategy:
 * 1. List all rental services, identify base listings (variantId is null).
 * 2. For each base, GET detail (has embedded variants[], pricingRule, durations).
 * 3. Upsert the base row, then upsert each embedded variant (fetching variant detail
 *    when needed for its own pricingRule).
 */
class GhlServiceSyncService
{
    private const RENTAL_INDUSTRY = 'rental';

    public function __construct(
        private GhlClient $client,
    ) {}

    /** @return array{pulled: int, errors: int, error_details: array} */
    public function pullServices(string $tenantId): array
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $list = $this->client->get('calendars/services', [
            'locationId' => $locationId,
            'industryType' => self::RENTAL_INDUSTRY,
        ]);

        $services = $list['services'] ?? [];

        // Only process base listings; variants come from embedded variants[] on detail.
        $bases = collect($services)->filter(fn ($s) => empty($s['variantId']));

        $pulled = 0;
        $errors = [];

        foreach ($bases as $summary) {
            try {
                $count = $this->syncBaseService($summary['_id'], $tenantId, $locationId);
                $pulled += $count;
            } catch (\Exception $e) {
                $errors[] = [
                    'service_id' => $summary['_id'] ?? null,
                    'name' => $summary['name'] ?? null,
                    'error' => $e->getMessage(),
                ];
                Log::error('GHL rental service pull failed', [
                    'service' => $summary['_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['pulled' => $pulled, 'errors' => count($errors), 'error_details' => $errors];
    }

    /**
     * Sync a base rental listing and all its embedded variants.
     *
     * @return int Number of product rows upserted (base + variants)
     */
    private function syncBaseService(string $ghlBaseId, string $tenantId, string $locationId): int
    {
        $detail = $this->fetchServiceDetail($ghlBaseId, $locationId);
        $base = $this->upsertFromDetail($detail, $tenantId, null);

        $count = 1;
        $embeddedVariants = $detail['variants'] ?? [];

        foreach ($embeddedVariants as $embedded) {
            $variantGhlId = $embedded['id'] ?? null;

            if (! $variantGhlId || $variantGhlId === $ghlBaseId) {
                continue;
            }

            // Variant detail carries its own pricingRule (base detail only has summary).
            $variantDetail = $this->fetchServiceDetail($variantGhlId, $locationId);
            $this->upsertFromDetail($variantDetail, $tenantId, $base->id);
            $count++;
        }

        return $count;
    }

    private function fetchServiceDetail(string $ghlId, string $locationId): array
    {
        $response = $this->client->get("calendars/services/{$ghlId}", [
            'locationId' => $locationId,
            'industryType' => self::RENTAL_INDUSTRY,
        ]);

        return $response['service'] ?? $response;
    }

    private function upsertFromDetail(array $detail, string $tenantId, ?string $parentProductId): Product
    {
        $ghlId = $detail['_id'] ?? $detail['id'] ?? null;

        if (! $ghlId) {
            throw new \RuntimeException('GHL service detail missing _id');
        }

        // Resolve parent from GHL variantId when not passed explicitly.
        if ($parentProductId === null && ! empty($detail['variantId'])) {
            $parentProductId = Product::where('tenant_id', $tenantId)
                ->where('ghl_service_id', $detail['variantId'])
                ->value('id');
        }

        $isVariant = $parentProductId !== null;

        $attributes = [
            'name' => $detail['name'],
            'product_type' => 'SERVICE',
            'parent_product_id' => $parentProductId,
            'variant_name' => $detail['variantName'] ?? ($isVariant ? 'Variant' : 'Regular'),
            'description' => $detail['description'] ?? null,
            'slug' => $detail['slug'] ?? null,
            'status' => (! empty($detail['isActive']) && empty($detail['deleted'])) ? 'active' : 'draft',
            'image' => $detail['coverImage'] ?? null,
            'medias' => $this->mapImages($detail['images'] ?? []),
            'available_quantity' => $detail['quantity'] ?? null,
            'max_quantity' => $detail['maxQuantity'] ?? null,
            'booking_unit' => $detail['bookingUnit'] ?? null,
            'min_duration' => $detail['minDuration'] ?? null,
            'max_duration' => $detail['maxDuration'] ?? null,
            'duration_unit' => $detail['minDurationUnit'] ?? $detail['bookingUnit'] ?? null,
            'booking_start_time' => $detail['bookingStartTime'] ?? null,
            'booking_end_time' => $detail['bookingEndTime'] ?? null,
            'pricing_rule' => $this->mapPricingRule($detail),
            // Payments-layer product (auto-created by GHL per service/variant).
            'engage_product_id' => $detail['productId'] ?? null,
            'ghl_service_category_id' => $detail['serviceCategoryId'] ?? null,
            'ghl_service_location_id' => $this->resolveServiceLocationId($detail),
            'ghl_metadata' => $this->mapMetadata($detail),
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
        ];

        return Product::updateOrCreate(
            ['tenant_id' => $tenantId, 'ghl_service_id' => $ghlId],
            $attributes
        );
    }

    /** GHL pricingRule → our pricing_rule JSON (same rules shape by design). */
    private function mapPricingRule(array $detail): ?array
    {
        $rule = $detail['pricingRule'] ?? null;

        if (! $rule) {
            $amount = $detail['payment']['amount'] ?? null;

            return $amount !== null
                ? ['base_price' => (float) $amount, 'base_price_strategy' => 'per_day', 'rules' => []]
                : null;
        }

        return [
            'name' => $rule['name'] ?? null,
            'applies_to' => $rule['appliesTo'] ?? 'rental',
            'base_price' => (float) ($rule['basePrice']['value'] ?? $detail['payment']['amount'] ?? 0),
            'base_price_strategy' => $rule['basePrice']['strategy'] ?? 'per_day',
            'rules' => $rule['rules'] ?? [],
            'security_deposit_amount' => isset($rule['securityDeposit']['amount']) ? (float) $rule['securityDeposit']['amount'] : null,
            'security_deposit_refundable' => $rule['securityDeposit']['refundable'] ?? true,
            'payment_terms' => $rule['paymentTerms'] ?? null,
            'ghl_pricing_rule_id' => $rule['id'] ?? null,
        ];
    }

    private function mapImages(array $images): ?array
    {
        if (empty($images)) {
            return null;
        }

        return collect($images)->map(fn ($img) => [
            'id' => $img['_id'] ?? null,
            'title' => $img['name'] ?? null,
            'url' => $img['url'] ?? null,
            'type' => 'image',
            'isFeatured' => ($img['position'] ?? null) === 0,
        ])->values()->all();
    }

    private function resolveServiceLocationId(array $detail): ?string
    {
        $locations = $detail['locations'] ?? [];

        if (! empty($locations)) {
            return $locations[0]['_id'] ?? $locations[0]['id'] ?? null;
        }

        return null;
    }

    private function mapMetadata(array $detail): ?array
    {
        $metadata = array_filter([
            'industryType' => $detail['industryType'] ?? self::RENTAL_INDUSTRY,
            'ghl_variant_id' => $detail['variantId'] ?? null,
            'ghl_payments_product_id' => $detail['productId'] ?? null,
            'bookingPeriodType' => $detail['bookingPeriodType'] ?? null,
            'isVariantsEnabled' => $detail['isVariantsEnabled'] ?? null,
            'teamMembers' => $detail['teamMembers'] ?? null,
            'addOns' => $detail['addOns'] ?? null,
            'resources' => $detail['resources'] ?? null,
            'locations' => $detail['locations'] ?? null,
            'embedded_variant_ids' => collect($detail['variants'] ?? [])
                ->pluck('id')
                ->filter()
                ->values()
                ->all() ?: null,
        ], fn ($value) => $value !== null && $value !== []);

        return $metadata === [] ? null : $metadata;
    }
}
