<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ghl_invoice_id' => $this->ghl_invoice_id,
            'booking_id' => $this->booking_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            // Relational items() as of the 2026-08-10 transactions refactor
            // (the legacy `items` JSON column is frozen, no longer written)
            // — falls back to the JSON snapshot for any pre-refactor row
            // that was never re-synced, so old GHL-pulled rows don't
            // suddenly show an empty items list. Uses getRelation() rather
            // than the bare $this->items property — `items` is BOTH a
            // relation method and a JSON-cast column name, and Eloquent's
            // attribute resolution checks casts before relations, so
            // $this->items would otherwise always return the (possibly
            // stale/empty) JSON column instead of the loaded relation.
            'items' => $this->relationLoaded('items') && $this->getRelation('items')->isNotEmpty()
                ? ProductTransactionItemResource::collection($this->getRelation('items'))
                : ($this->items ?? []),
            'ghl_invoice' => $this->when($this->ghl_invoice_id !== null, fn () => [
                'id' => $this->ghl_invoice_id,
                'number' => $this->ghl_invoice_number,
                'status' => $this->ghl_invoice_status,
            ]),
            'ghl_invoice_url' => $this->ghl_invoice_url,
            // Lead Connector's public invoice page (set by the list endpoint; otherwise from this row's own pay link).
            'ghl_invoice_view_url' => $this->resource->getAttribute('ghl_invoice_view_url') ?? $this->ghlInvoiceViewUrl(),
            'paid_at' => $this->paid_at,
            'invoice_date' => $this->invoice_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
