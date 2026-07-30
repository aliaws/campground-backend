<?php

namespace Database\Factories;

use App\Models\EngageOrganizationLocation;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $locationId = EngageOrganizationLocation::query()->where('is_default', true)->value('id')
            ?? EngageOrganizationLocation::query()->value('id')
            ?? (string) \Illuminate\Support\Str::uuid();

        return [
            'name' => fake()->words(3, true),
            'product_type' => 'PHYSICAL',
            'status' => 'active',
            'available_in_store' => true,
            'price' => fake()->randomFloat(2, 1, 100),
            'quantity' => 10,
            'track_product_inventory' => false,
            'engage_organization_location_id' => $locationId,
        ];
    }

    public function trackingInventory(int $quantity = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'track_product_inventory' => true,
            'quantity' => $quantity,
        ]);
    }

    public function linkedToGhl(string $ghlProductId = 'ghl-product-1'): static
    {
        return $this->state(fn (array $attributes) => [
            'ghl_product_id' => $ghlProductId,
        ]);
    }
}
