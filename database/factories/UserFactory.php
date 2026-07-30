<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\EngageOrganizationLocation;
use App\Models\User;
use App\Models\UserVerification;
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
            'password' => static::$password ??= Hash::make('password'),
            'roles' => [User::ROLE_STAFF],
            'status' => User::STATUS_ACTIVE,
            'created_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->locationLinks()->exists()) {
                return;
            }

            $locationId = EngageOrganizationLocation::query()->where('is_default', true)->value('id')
                ?? EngageOrganizationLocation::query()->value('id');

            if (is_string($locationId) && $locationId !== '') {
                $user->attachLocation($locationId);
            }
        });
    }

    public function customerPendingVerification(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'roles' => [User::ROLE_CUSTOMER],
                'status' => User::STATUS_PENDING,
                'password' => null,
                'customer_id' => $linked->id,
                'email' => $linked->email,
                'name' => $linked->name,
                'created_by' => null,
            ];
        })->afterCreating(function (User $user) {
            UserVerification::create([
                'user_id' => $user->id,
                'type' => UserVerification::TYPE_EMAIL_VERIFICATION,
                'jti' => (string) Str::uuid(),
                'code_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(30),
            ]);
        });
    }

    /** Email verified, password not yet set — still status=pending. */
    public function customerVerified(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'roles' => [User::ROLE_CUSTOMER],
                'status' => User::STATUS_PENDING,
                'password' => null,
                'customer_id' => $linked->id,
                'email' => $linked->email,
                'name' => $linked->name,
                'created_by' => null,
            ];
        })->afterCreating(function (User $user) {
            UserVerification::create([
                'user_id' => $user->id,
                'type' => UserVerification::TYPE_EMAIL_VERIFICATION,
                'jti' => (string) Str::uuid(),
                'code_hash' => null,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(30),
                'verified_at' => now(),
            ]);
        });
    }

    public function customerActive(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $linked = $customer ?? Customer::factory()->create([
                'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
                'name' => $attributes['name'] ?? fake()->name(),
            ]);

            return [
                'roles' => [User::ROLE_CUSTOMER],
                'status' => User::STATUS_ACTIVE,
                'password' => static::$password ??= Hash::make('password'),
                'customer_id' => $linked->id,
                'email' => $linked->email,
                'name' => $linked->name,
                'created_by' => null,
            ];
        });
    }
}
