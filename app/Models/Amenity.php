<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Amenity extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'icon',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function productRentals(): BelongsToMany
    {
        return $this->belongsToMany(ProductRental::class, 'product_rental_amenities');
    }

    /** @deprecated Use productRentals() */
    public function services(): BelongsToMany
    {
        return $this->productRentals();
    }
}
