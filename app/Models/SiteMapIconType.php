<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteMapIconType extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'image_url',
        'tenant_id',
    ];

    public function elements(): HasMany
    {
        return $this->hasMany(SiteMapElement::class);
    }
}
