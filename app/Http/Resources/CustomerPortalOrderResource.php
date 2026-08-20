<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authenticated customer portal product order payload.
 */
class CustomerPortalOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isPaid = $this->isPaid();

        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_url' => $isPaid ? null : $this->ghl_invoice_url,
            'payment_status' => $this->ghl_invoice_status,
            'invoice_view_url' => $this->ghlInvoiceViewUrl(),
            'is_paid' => $isPaid,
            'organization_name' => $this->whenLoaded('organizationLocation', fn () => $this->organizationLocation?->name),
            'items' => $this->relationLoaded('items')
                ? $this->getRelation('items')->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name_snapshot,
                    'product_image_url' => $item->relationLoaded('product') ? $item->product?->image_path : null,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total' => (float) ($item->unit_price * $item->quantity),
                ])
                : ($this->items ?? []),
            'paid_at' => $this->paid_at,
            'invoice_date' => $this->invoice_date,
            'created_at' => $this->created_at,
        ];
    }
}
