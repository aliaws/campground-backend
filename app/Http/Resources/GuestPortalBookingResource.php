<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Authenticated guest portal booking payload. */
class GuestPortalBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isPaid = $this->isPaid();
        $isCancelled = $this->status === 'cancelled';
        $canCancel = ! $isPaid && ! $isCancelled;

        return [
            'id' => $this->id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'booking_start_time' => $this->booking_start_time,
            'booking_end_time' => $this->booking_end_time,
            'check_in_date' => $this->check_in_date?->format('Y-m-d'),
            'check_out_date' => $this->check_out_date?->format('Y-m-d'),
            'quantity' => $this->quantity,
            'total_amount' => (float) $this->total_amount,
            'security_deposit_amount' => (float) $this->security_deposit_amount,
            'status' => $this->status,
            // Hide pay/invoice links once cancelled — voided invoices must not stay actionable.
            'payment_url' => $isCancelled ? null : $this->ghl_invoice_url,
            'payment_status' => $this->ghl_invoice_status,
            'invoice_view_url' => $isCancelled ? null : $this->ghlInvoiceViewUrl(),
            'is_paid' => $isPaid,
            'can_cancel' => $canCancel,
            'created_at' => $this->created_at,
        ];
    }
}
