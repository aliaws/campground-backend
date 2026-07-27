<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
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

    /** Service listings (Product rows with product_rental_id set) this feature is assigned to. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'service_features');
    }
}
