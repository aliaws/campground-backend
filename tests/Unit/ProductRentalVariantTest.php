<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductRentalVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_listing_is_identified_by_ghl_id_equals_service_id(): void
    {
        $base = new ProductRental([
            'ghl_id' => 'svc-base',
            'service_id' => 'svc-base',
        ]);
        $variant = new ProductRental([
            'ghl_id' => 'svc-premium',
            'service_id' => 'svc-base',
        ]);

        $this->assertTrue($base->isBaseListing());
        $this->assertFalse($variant->isBaseListing());
    }

    public function test_resolve_base_rental_prefers_ghl_base_row_over_stale_fk(): void
    {
        $tenantId = (string) Str::ulid();

        $product = Product::create([
            'tenant_id' => $tenantId,
            'name' => 'Lakeside Pine Retreat Campsite',
            'product_type' => 'SERVICE',
            'status' => 'active',
            'price' => 96.0,
        ]);

        $baseRental = ProductRental::create([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'ghl_id' => 'svc-base',
            'service_id' => 'svc-base',
            'name' => 'Regular',
            'is_active' => true,
            'listing_price' => 96.0,
        ]);

        $premiumRental = ProductRental::create([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'ghl_id' => 'svc-premium',
            'service_id' => 'svc-base',
            'name' => 'Premium',
            'is_active' => true,
        ]);

        // Simulate stale FK and stale product.price (premium min).
        $product->update(['product_rental_id' => $premiumRental->id, 'price' => 23.0]);

        $product->load('rentals');

        $this->assertSame($baseRental->id, $product->resolveBaseRental()?->id);
        $this->assertSame(96.0, $product->defaultVariantPrice());
    }

    public function test_default_variant_price_uses_product_snapshot_not_variant_min(): void
    {
        $product = Product::create([
            'tenant_id' => (string) Str::ulid(),
            'name' => 'Test Rental',
            'product_type' => 'SERVICE',
            'status' => 'active',
            'price' => 96.0,
        ]);

        $this->assertSame(96.0, $product->defaultVariantPrice());
    }
}
