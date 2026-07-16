<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMapElement extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_map_id',
        'type',
        'product_rental_id',
        'icon_key',
        'shape',
        'icon_style',
        'label',
        'description',
        'x',
        'y',
        'width',
        'height',
        'points',
        'rotation',
        'color',
        'stroke_width',
        'opacity',
        'z_index',
        'is_visible',
        'category',
        'metadata',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'rotation' => 'float',
            'opacity' => 'float',
            'stroke_width' => 'float',
            'z_index' => 'integer',
            'is_visible' => 'boolean',
            'points' => 'json',
            'metadata' => 'json',
        ];
    }

    public function siteMap(): BelongsTo
    {
        return $this->belongsTo(SiteMap::class);
    }

    public function productRental(): BelongsTo
    {
        return $this->belongsTo(ProductRental::class);
    }
}
