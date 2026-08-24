<?php

namespace App\Rules;

use App\Models\EngageProduct;
use App\Models\EngageProductRental;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The booking `product_id` may be a products.id (default variant) or a
 * product_rentals.id (any other variant) — see RentalResolver.
 */
class BookableRentalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute field is invalid.');

            return;
        }

        $exists = EngageProduct::whereKey($value)->exists()
            || EngageProductRental::whereKey($value)->exists();

        if (! $exists) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
