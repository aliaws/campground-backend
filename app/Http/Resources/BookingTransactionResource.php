<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Small, purpose-built resource used only inside BookingResource's
 * `transactions` field (a RentalTransaction collection as of the
 * 2026-08-10 transactions refactor) — outputs exactly the subset the
 * frontend actually reads, with the SAME field names the old generic
 * TransactionResource used (`payment_method`/`payment_status`/
 * `total_amount`/`transaction_date`), so components that read
 * `booking.transactions[0].payment_method` etc. (BookingDetailsModal,
 * EditCheckInOutModal's isPaid(), useBookingOrchestration) need zero
 * frontend changes.
 */
class BookingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->status,
            'total_amount' => (float) $this->amount,
            'transaction_date' => $this->paid_at ?? $this->created_at,
        ];
    }
}
