<?php

namespace Database\Factories;

use App\Models\EngageCustomer;
use App\Models\EngageOrganizationLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EngageCustomer>
 */
class EngageCustomerFactory extends Factory
{
    protected $model = EngageCustomer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('##########'),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (EngageCustomer $customer) {
            if ($customer->locationLinks()->exists()) {
                return;
            }

            $locationId = EngageOrganizationLocation::query()->where('is_default', true)->value('id')
                ?? EngageOrganizationLocation::query()->value('id');

            if (is_string($locationId) && $locationId !== '') {
                $customer->attachLocation($locationId);
            }
        });
    }
}
