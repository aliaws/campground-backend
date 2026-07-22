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
        'icon_type_id',
        'shape',
        'icon_style',
        'font_size',
        'label',
        'description',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'color',
        'opacity',
        'z_index',
        'is_visible',
        'category',
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
            'z_index' => 'integer',
            'is_visible' => 'boolean',
            'font_size' => 'integer',
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

    public function iconType(): BelongsTo
    {
        return $this->belongsTo(SiteMapIconType::class);
    }
}
