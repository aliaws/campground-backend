<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return [];
        }

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
            'transactions' => BookingTransactionResource::collection($this->whenLoaded('transactions')),
            // Business-rule eligibility for the staff "send invoice again" /
            // "void invoice" actions (who may use them is a separate
            // permission check). Decided here, not in the browser, so the
            // 24-hour rule uses the server's clock.
            'can_resend_invoice' => $this->whenLoaded('transactions', fn () => $this->canResendInvoice()),
            'can_void_invoice' => $this->whenLoaded('transactions', fn () => $this->canVoidInvoice()),
            // No Lead Connector booking or invoice yet: staff can create and send the invoice, or cancel.
            'awaiting_invoice' => $this->whenLoaded('transactions', fn () => $this->isAwaitingInvoice()),
            // Paid, but no calendar booking in Lead Connector: staff create it,
            // move it, or cancel and refund. covered_by is set when the same
            // stay exists in Lead Connector as another booking (paid twice).
            'missing_in_lead_connector' => $this->whenLoaded('transactions', fn () => $this->isPaidMissingInLeadConnector()),
            'covered_by_booking_id' => $this->whenLoaded('transactions', fn () => $this->isPaidMissingInLeadConnector()
                ? $this->coveringLeadConnectorBooking()?->id
                : null),
            // Refunds: the paid campsite transaction a refund is made against,
            // what has been refunded so far, and what is left.
            'refund_transaction_id' => $this->whenLoaded('transactions', fn () => $this->transactions->first(fn ($t) => $t->isPaid())?->id),
            'refunded_amount' => $this->refundedAmount(),
            'refundable_amount' => $this->whenLoaded('transactions', fn () => max(0, round(
                (float) ($this->transactions->first(fn ($t) => $t->isPaid())?->amount ?? 0) - $this->refundedAmount(),
                2,
            ))),
            'created_by' => $this->created_by,
            // Where the booking was made: pos | website | lead_connector.
            'source' => $this->source,
            'engage_organization_location_id' => $this->engage_organization_location_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** From the list query's withSum() when present, otherwise one query. */
    private function refundedAmount(): float
    {
        $amount = array_key_exists('refunded_amount', $this->resource->getAttributes())
            ? $this->resource->getAttributes()['refunded_amount']
            : $this->resource->refunds()->sum('amount');

        return round((float) $amount, 2);
    }
}
