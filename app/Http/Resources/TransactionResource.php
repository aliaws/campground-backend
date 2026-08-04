<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->relationLoaded('transactionable') && $this->transactionable instanceof Booking
            ? $this->transactionable
            : null;
        $order = $this->relationLoaded('transactionable') && $this->transactionable instanceof Order
            ? $this->transactionable
            : null;

        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_id' => $this->customer_id,
            'transactionable_type' => $this->transactionable_type,
            'transactionable_id' => $this->transactionable_id,
            'booking' => $booking ? new BookingResource($booking) : null,
            'booking_id' => $booking?->id,
            'order' => $order ? [
                'id' => $order->id,
                'status' => $order->status,
                'total_amount' => (float) $order->total_amount,
            ] : null,
            'order_id' => $order?->id,
            'total_amount' => (float) $this->total_amount,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'invoice_status' => $this->invoice_status,
            'transaction_date' => $this->transaction_date,
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
            'ghl_invoice' => $this->when(
                (bool) $this->ghl_invoice_id,
                fn () => [
                    'id' => $this->ghl_invoice_id,
                    'number' => $this->ghl_invoice_number,
                    'status' => $this->ghl_invoice_status,
                    'ghl_booking_id' => $booking?->ghl_booking_id,
                ],
            ),
            'ghl_invoice_url' => $this->ghl_invoice_url,
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
