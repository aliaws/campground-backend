<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_id' => $this->customer_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'product_id' => $this->product_id,
            'product_rental_id' => $this->product_rental_id,
            'product_rental' => new ProductRentalResource($this->whenLoaded('productRental')),
            'booking_start_time' => $this->booking_start_time,
            'booking_end_time' => $this->booking_end_time,
            // check_in_date/check_out_date are date-only fields — format explicitly,
            // since the Eloquent 'date' cast otherwise serializes to a full ISO
            // datetime (e.g. "2026-08-13T00:00:00.000000Z"), which breaks any
            // frontend code that treats these as plain "YYYY-MM-DD" strings.
            'check_in_date' => $this->check_in_date?->format('Y-m-d'),
            'check_out_date' => $this->check_out_date?->format('Y-m-d'),
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'base_amount' => (float) $this->base_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'security_deposit_amount' => (float) $this->security_deposit_amount,
            'price_breakdown' => $this->price_breakdown,
            'status' => $this->status,
            'ghl_opportunity_id' => $this->ghl_opportunity_id,
            'ghl_booking_id' => $this->ghl_booking_id,
            'ghl_invoice_id' => $this->ghl_invoice_id,
            'ghl_invoice_number' => $this->ghl_invoice_number,
            'ghl_invoice_status' => $this->ghl_invoice_status,
            'ghl_invoice_url' => $this->ghl_invoice_url,
            'ghl_invoice_view_url' => $this->ghlInvoiceViewUrl(),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'created_by' => $this->created_by,
            'tenant_id' => $this->tenant_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
