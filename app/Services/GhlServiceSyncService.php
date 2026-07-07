<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalPricingRule;
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

    /**
     * @return array{pulled: int, errors: int, error_details: array}
     *
     * Base-listing details and their embedded variants' details are each
     * fetched in one concurrent batch (via GhlClient::poolGet) rather than
     * one HTTP round trip at a time — same total number of GHL calls, just
     * issued in parallel, cutting wall-clock time from ~10-15s to ~2-3s for
     * a typical catalog. DB upserts stay sequential (they were never the
     * bottleneck) and still happen base-first so variants can resolve their
     * parent's Product id via upsertFromDetail().
     */
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
        $bases = collect($services)->filter(fn ($s) => empty($s['variantId']))->values();

        $pulled = 0;
        $errors = [];

        $baseResults = $this->client->poolGet(
            $bases->mapWithKeys(fn ($s) => [$s['_id'] => $this->serviceDetailRequest($s['_id'], $locationId)])->all()
        );

        $baseDetails = [];
        foreach ($baseResults as $ghlBaseId => $result) {
            if ($result instanceof \Throwable) {
                $errors[] = ['service_id' => $ghlBaseId, 'name' => null, 'error' => $result->getMessage()];
                Log::error('GHL rental base detail fetch failed', ['service' => $ghlBaseId, 'error' => $result->getMessage()]);

                continue;
            }

            $baseDetails[$ghlBaseId] = $result['service'] ?? $result;
        }

        // Gather every embedded variant id across all fetched bases so their
        // details can also be fetched as one concurrent batch.
        $variantRequests = [];
        foreach ($baseDetails as $ghlBaseId => $detail) {
            foreach ($detail['variants'] ?? [] as $embedded) {
                $variantId = $embedded['id'] ?? null;
                if ($variantId && $variantId !== $ghlBaseId) {
                    $variantRequests[$variantId] = $this->serviceDetailRequest($variantId, $locationId);
                }
            }
        }

        $variantResults = $this->client->poolGet($variantRequests);

        foreach ($baseDetails as $ghlBaseId => $detail) {
            try {
                $base = $this->upsertFromDetail($detail, $tenantId, null);
                $pulled++;

                foreach ($detail['variants'] ?? [] as $embedded) {
                    $variantId = $embedded['id'] ?? null;
                    if (! $variantId || $variantId === $ghlBaseId) {
                        continue;
                    }

                    $variantResult = $variantResults[$variantId] ?? null;

                    if ($variantResult === null || $variantResult instanceof \Throwable) {
                        $errors[] = [
                            'service_id' => $variantId,
                            'name' => $embedded['name'] ?? null,
                            'error' => $variantResult?->getMessage() ?? 'Variant detail fetch failed',
                        ];

                        continue;
                    }

                    $variantDetail = $variantResult['service'] ?? $variantResult;
                    $this->upsertFromDetail($variantDetail, $tenantId, $base->id);
                    $pulled++;
                }
            } catch (\Exception $e) {
                $errors[] = ['service_id' => $ghlBaseId, 'name' => $detail['name'] ?? null, 'error' => $e->getMessage()];
                Log::error('GHL rental service pull failed', ['service' => $ghlBaseId, 'error' => $e->getMessage()]);
            }
        }

        return ['pulled' => $pulled, 'errors' => count($errors), 'error_details' => $errors];
    }

    private function serviceDetailRequest(string $ghlId, string $locationId): array
    {
        return [
            'endpoint' => "calendars/services/{$ghlId}",
            'query' => ['locationId' => $locationId, 'industryType' => self::RENTAL_INDUSTRY],
        ];
    }

    /**
     * Identity now lives on Rental (rentals.ghl_service_id), not Product —
     * find the existing Rental first to resolve which Product to update,
     * rather than matching on products.ghl_service_id directly.
     */
    private function upsertFromDetail(array $detail, string $tenantId, ?string $parentProductId): Product
    {
        $ghlId = $detail['_id'] ?? $detail['id'] ?? null;

        if (! $ghlId) {
            throw new \RuntimeException('GHL service detail missing _id');
        }

        // Resolve parent from GHL variantId when not passed explicitly.
        if ($parentProductId === null && ! empty($detail['variantId'])) {
            $parentProductId = Rental::where('tenant_id', $tenantId)
                ->where('ghl_service_id', $detail['variantId'])
                ->value('product_id');
        }

        $isVariant = $parentProductId !== null;

        $productAttributes = [
            'name' => $detail['name'],
            'product_type' => 'SERVICE',
            'description' => $detail['description'] ?? null,
            'slug' => $detail['slug'] ?? null,
            'status' => (! empty($detail['isActive']) && empty($detail['deleted'])) ? 'active' : 'draft',
            'image' => $detail['coverImage'] ?? null,
            'medias' => $this->mapImages($detail['images'] ?? []),
            // Payments-layer product (auto-created by GHL per service/variant).
            'engage_product_id' => $detail['productId'] ?? null,
            'engage_sync_status' => 'synced',
            'engage_last_synced_at' => now(),
            'tenant_id' => $tenantId,
        ];

        $existingRental = Rental::where('tenant_id', $tenantId)->where('ghl_service_id', $ghlId)->first();

        if ($existingRental) {
            $product = $existingRental->product;
            $product->update($productAttributes);
        } else {
            $product = Product::create($productAttributes);
        }

        $rental = Rental::updateOrCreate(
            ['product_id' => $product->id],
            [
                'parent_product_id' => $parentProductId,
                'variant_name' => $detail['variantName'] ?? ($isVariant ? 'Variant' : 'Regular'),
                'available_quantity' => $detail['quantity'] ?? null,
                'max_quantity' => $detail['maxQuantity'] ?? null,
                'booking_unit' => $detail['bookingUnit'] ?? null,
                'min_duration' => $detail['minDuration'] ?? null,
                'max_duration' => $detail['maxDuration'] ?? null,
                'duration_unit' => $detail['minDurationUnit'] ?? $detail['bookingUnit'] ?? null,
                'booking_start_time' => $detail['bookingStartTime'] ?? null,
                'booking_end_time' => $detail['bookingEndTime'] ?? null,
                'industry_type' => $detail['industryType'] ?? self::RENTAL_INDUSTRY,
                'ghl_service_id' => $ghlId,
                'ghl_service_category_id' => $detail['serviceCategoryId'] ?? null,
                'ghl_metadata' => $this->mapMetadata($detail),
                'tenant_id' => $tenantId,
            ]
        );

        $this->upsertPricingRule($rental, $tenantId, $this->mapPricingRule($detail));

        return $product;
    }

    private function upsertPricingRule(Rental $rental, string $tenantId, ?array $pricingRule): void
    {
        if ($pricingRule === null) {
            return;
        }

        RentalPricingRule::updateOrCreate(
            ['rental_id' => $rental->id, 'priority' => 1],
            [
                'name' => $pricingRule['name'] ?? 'Default',
                'applies_to' => $pricingRule['applies_to'] ?? 'rental',
                'base_price' => $pricingRule['base_price'] ?? 0,
                'base_price_strategy' => $pricingRule['base_price_strategy'] ?? 'per_day',
                'rules' => $pricingRule['rules'] ?? null,
                'security_deposit_amount' => $pricingRule['security_deposit_amount'] ?? null,
                'security_deposit_refundable' => $pricingRule['security_deposit_refundable'] ?? true,
                'payment_terms' => $pricingRule['payment_terms'] ?? null,
                'ghl_pricing_rule_id' => $pricingRule['ghl_pricing_rule_id'] ?? null,
                'tenant_id' => $tenantId,
            ]
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

    private function mapMetadata(array $detail): ?array
    {
        $metadata = array_filter([
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
