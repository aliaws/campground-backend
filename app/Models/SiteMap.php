<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteMap extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'image_url',
        'is_default',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function elements(): HasMany
    {
        return $this->hasMany(SiteMapElement::class)->orderBy('z_index');
    }
}
