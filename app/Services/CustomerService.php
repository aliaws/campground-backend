<?php

namespace App\Services;

use App\Models\Customer;

class CustomerService
{
    /**
     * Match an existing customer by email, then phone, before creating a new one.
     *
     * @param  ?string  $createdBy  Only applied when actually creating a new row (see
     *                              User::createdByLabel()) — never overwrites an existing
     *                              customer's original creator on a dedup match.
     */
    public function findOrCreate(array $data, string $tenantId, ?string $createdBy = null): Customer
    {
        $customer = null;

        if (! empty($data['email'])) {
            $customer = Customer::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
                ->first();
        }

        if (! $customer && ! empty($data['phone'])) {
            $customer = Customer::where('tenant_id', $tenantId)
                ->where('phone', $data['phone'])
                ->first();
        }

        if ($customer) {
            $patch = array_filter([
                'email' => $customer->email ?: ($data['email'] ?? null),
                'phone' => $customer->phone ?: ($data['phone'] ?? null),
                'address' => $customer->address ?: ($data['address'] ?? null),
            ]);

            if ($patch) {
                $customer->update($patch);
            }

            return $customer;
        }

        return Customer::create($data + ['tenant_id' => $tenantId, 'created_by' => $createdBy]);
    }
}
