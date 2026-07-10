<?php

namespace App\Rules;

use App\Models\Product;
use App\Models\ProductRental;
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

        $exists = Product::whereKey($value)->exists()
            || ProductRental::whereKey($value)->exists();

        if (! $exists) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
