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
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_amenities');
    }
}
