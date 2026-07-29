<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'staff',
            'tenant_id' => (string) Str::ulid(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function customerPendingVerification(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'tenant_id' => $attributes['tenant_id'] ?? (string) Str::ulid(),
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'role' => 'customer',
                'password' => null,
                'customer_id' => $linked->id,
                'tenant_id' => $linked->tenant_id,
                'email' => $linked->email,
                'name' => $linked->name,
                'customer_status' => 'pending_verification',
                'customer_registered_at' => now(),
                'customer_account_type' => 'email_verification',
                'customer_account_expires_at' => now()->addMinutes(30),
                'customer_account_token_hash' => hash('sha256', 'test-token'),
                'customer_verification_code_hash' => Hash::make('123456'),
                'customer_verification_attempts' => 0,
            ];
        });
    }

    public function customerVerified(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'tenant_id' => $attributes['tenant_id'] ?? (string) Str::ulid(),
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'role' => 'customer',
                'password' => null,
                'customer_id' => $linked->id,
                'tenant_id' => $linked->tenant_id,
                'email' => $linked->email,
                'name' => $linked->name,
                'customer_status' => 'verified',
                'customer_registered_at' => now()->subHour(),
                'customer_verified_at' => now(),
                'customer_account_type' => 'email_verification',
                'customer_account_expires_at' => now()->addMinutes(30),
                'customer_account_token_hash' => hash('sha256', 'test-token'),
                'customer_verification_code_hash' => null,
                'customer_verification_attempts' => 0,
            ];
        });
    }

    public function customerActive(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'tenant_id' => $attributes['tenant_id'] ?? (string) Str::ulid(),
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'role' => 'customer',
                'password' => static::$password ??= Hash::make('password'),
                'customer_id' => $linked->id,
                'tenant_id' => $linked->tenant_id,
                'email' => $linked->email,
                'name' => $linked->name,
                'customer_status' => 'active',
                'customer_registered_at' => now()->subDay(),
                'customer_verified_at' => now()->subDay(),
                'customer_account_token_hash' => null,
                'customer_account_type' => null,
                'customer_account_expires_at' => null,
                'customer_verification_code_hash' => null,
                'customer_verification_attempts' => 0,
            ];
        });
    }
}
