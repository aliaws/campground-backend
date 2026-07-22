<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteMapElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rental = $this->productRental;
        $product = $rental?->product;
        $iconType = $this->iconType;

        return [
            'id' => $this->id,
            'site_map_id' => $this->site_map_id,
            'type' => $this->type,
            'product_rental_id' => $this->product_rental_id,
            'icon_key' => $this->icon_key,
            'icon_type_id' => $this->icon_type_id,
            'shape' => $this->shape,
            'icon_style' => $this->icon_style,
            'font_size' => $this->font_size,
            'label' => $this->label ?: $product?->name,
            'description' => $this->description,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'color' => $this->color,
            'opacity' => $this->opacity,
            'z_index' => $this->z_index,
            'is_visible' => $this->is_visible,
            'category' => $this->category,
            // Rental-only display fields — resolved live from Product/ProductRental,
            // never persisted here, same "compute from the source" spirit as the
            // rest of the app's rental-detail handling.
            'rental' => $rental ? [
                'product_id' => $product?->id,
                'name' => $product?->name,
                'image' => $product?->image,
                'price' => $product?->price,
                'status' => $product?->status,
            ] : null,
            'icon_type' => $iconType ? [
                'id' => $iconType->id,
                'name' => $iconType->name,
                'image_url' => $iconType->image_url,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
