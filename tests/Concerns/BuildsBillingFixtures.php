<?php

namespace Tests\Concerns;

use App\Models\EngageOrganizationLocation;
use App\Models\User;
use App\Support\SessionJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Small fixtures for the usage-billing tests (run inside DatabaseTransactions). */
trait BuildsBillingFixtures
{
    protected function makeOrganization(string $name = 'Org'): EngageOrganizationLocation
    {
        return EngageOrganizationLocation::create([
            'name' => $name.' '.Str::random(5),
            'engage_location_id' => Str::random(20),
        ])->refresh();
    }

    protected function makeUser(array $roles, ?EngageOrganizationLocation $organization = null, array $attributes = []): User
    {
        $user = User::create($attributes + [
            'name' => 'User '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'password',
            'roles' => $roles,
            'status' => 'active',
        ]);
        if ($organization) {
            $user->attachLocation($organization->id);
        }

        return $user;
    }

    protected function tokenFor(User $user, ?EngageOrganizationLocation $organization = null): array
    {
        return ['Authorization' => 'Bearer '.SessionJwt::issue($user, $organization?->id), 'Accept' => 'application/json'];
    }

    protected function superadminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['superadmin']));
    }

    protected function makeProduct(EngageOrganizationLocation $organization, array $attributes = []): string
    {
        $id = Str::lower((string) Str::ulid());
        DB::table('engage_products')->insert($attributes + [
            'id' => $id,
            'name' => 'Product '.Str::random(4),
            'status' => 'active',
            'product_type' => 'PHYSICAL',
            'engage_organization_location_id' => $organization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function makeCustomer(array $organizations, array $attributes = []): string
    {
        $id = Str::lower((string) Str::ulid());
        DB::table('engage_customers')->insert($attributes + [
            'id' => $id,
            'name' => 'Customer '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($organizations as $organization) {
            DB::table('engage_customers_locations')->insert([
                'id' => Str::lower((string) Str::ulid()),
                'customer_id' => $id,
                'engage_organization_location_id' => $organization->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function makeBooking(EngageOrganizationLocation $organization, string $customerId, string $productId): string
    {
        $id = Str::lower((string) Str::ulid());
        DB::table('engage_bookings')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'check_in_date' => '2026-10-01',
            'check_out_date' => '2026-10-03',
            'total_amount' => 100,
            'status' => 'confirmed',
            'engage_organization_location_id' => $organization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
