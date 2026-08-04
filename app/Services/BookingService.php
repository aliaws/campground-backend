<?php

namespace App\Services;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Booking;
use App\Models\Product;
use App\Models\ProductRental;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class BookingService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private TransactionService $transactionService,
        private BookingPriceCalculator $priceCalculator,
        private GhlRentalGateway $gateway,
        private RentalResolver $resolver,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Booking::query();

        if (! empty($filters['engage_organization_location_id'])) {
            $query->where('engage_organization_location_id', $filters['engage_organization_location_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('check_in_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('check_out_date', '<=', $filters['date_to']);
        }

        return $query->with(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Price a booking without creating it. Pricing rules, duration bounds and
     * the stock ceiling all come LIVE from GHL (via the gateway's short cache);
     * only the overlapping-booking count is local. Never throws on
     * insufficient stock — returns remaining_quantity/is_available so the UI
     * can show "2 of 3 available" instead of an error. Duration violations
     * still throw (hard validation), and GHL being unreachable throws a
     * RuntimeException the controllers turn into a friendly 422.
     */
    public function quote(Product $product, ProductRental $rental, string $checkIn, string $checkOut, int $quantity = 1): array
    {
        $detail = $this->gateway->fetchRentalDetail($rental);

        return $this->quoteFromDetail($detail, $rental, $checkIn, $checkOut, $quantity);
    }

    private function quoteFromDetail(GhlServiceDetail $detail, ProductRental $rental, string $checkIn, string $checkOut, int $quantity): array
    {
        $this->assertDurationAllowed($detail, $checkIn, $checkOut);

        $remaining = $this->remainingStock($rental, $detail->quantity(), $checkIn, $checkOut);

        return $this->priceCalculator->quote($detail->pricingRule(), $checkIn, $checkOut, $quantity) + [
            'remaining_quantity' => $remaining,
            'is_available' => $remaining === null || $remaining >= $quantity,
        ];
    }

    /**
     * Units still bookable for this date range — null means unlimited. Stock
     * ceiling comes from the live GHL detail; bookings are counted locally
     * per rental variant (each variant's stock is independent).
     */
    public function remainingStock(ProductRental $rental, ?int $stock, string $checkIn, string $checkOut): ?int
    {
        if ($stock === null) {
            return null;
        }

        $booked = (int) Booking::where('product_rental_id', $rental->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->sum('quantity');

        return max($stock - $booked, 0);
    }

    /**
     * $data['product_id'] may be a products.id (default variant) or a
     * product_rentals.id (other variants) — resolved here; the booking
     * always stores product_id = base listing + product_rental_id = variant,
     * plus a snapshot of the GHL booking times taken at creation.
     *
     * @param  bool  $autoConfirm  When true (staff-created, default), the booking is
     *                             immediately synced to GHL — except 'cash' payments, which are always created
     *                             local-only (see $deferGhl below). When false (customer-submitted), it's created as
     *                             a 'requested' record with a Text2Pay invoice — see confirm() for what turns it real.
     */
    public function create(array $data, bool $autoConfirm = true): Booking
    {
        $resolved = $this->resolver->resolve($data['product_id'], $data['engage_organization_location_id']);

        if (! $resolved) {
            throw new \InvalidArgumentException('Product must be a bookable service for bookings.');
        }

        [$product, $rental] = $resolved;
        $quantity = (int) ($data['quantity'] ?? 1);

        $detail = $this->gateway->fetchRentalDetail($rental);
        $quote = $this->quoteFromDetail($detail, $rental, $data['check_in_date'], $data['check_out_date'], $quantity);

        if (! $quote['is_available']) {
            throw new \InvalidArgumentException(
                "Not available for the selected dates. Remaining quantity: {$quote['remaining_quantity']}."
            );
        }

        $booking = Booking::create(array_merge($data, [
            'product_id' => $product->id,
            'product_rental_id' => $rental->id,
            'quantity' => $quantity,
            'booking_start_time' => $detail->bookingStartTime(),
            'booking_end_time' => $detail->bookingEndTime(),
            'base_amount' => $quote['subtotal'],
            'discount_amount' => $quote['discount_amount'],
            'total_amount' => $quote['total_amount'],
            'security_deposit_amount' => $quote['security_deposit_amount'],
            'price_breakdown' => $quote,
            'status' => $autoConfirm ? 'pending' : 'requested',
        ]));

        // 'cash' bookings are always created local-only — GHL only learns about
        // them once the cash payment is actually recorded (see payCash()), never
        // at creation time.
        $deferGhl = $autoConfirm && ($data['payment_method'] ?? null) === 'cash';

        if ($deferGhl) {
            // Intentionally left unsynced.
        } elseif ($autoConfirm) {
            try {
                $this->ghlBookingService->createBooking($booking);
            } catch (\Exception $e) {
                Log::error('GHL booking creation failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                $this->ghlBookingService->createText2PayInvoice($booking);
                $this->transactionService->autoCreateFromBooking($booking);
            } catch (\Exception $e) {
                Log::error('GHL Text2Pay invoice creation failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $booking->fresh()->load(['customer', 'product', 'productRental']);
    }

    /**
     * Turns a customer-submitted 'requested' booking into a real one: syncs the
     * contact to GHL, creates the GHL booking + invoice (which emails the payment
     * link), marks it confirmed, and creates the local transaction record. This is
     * the ONLY path that should ever move a booking out of 'requested'.
     */
    public function confirm(Booking $booking): Booking
    {
        if ($booking->status !== 'requested') {
            throw new \InvalidArgumentException('Only requested bookings can be confirmed this way.');
        }

        $this->ghlBookingService->createBooking($booking);
        $booking->update(['status' => 'confirmed']);
        $this->transactionService->autoCreateFromBooking($booking);

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Auto-confirm a booking whose payment was just marked paid (webhook,
     * invoice reconciliation, cash pay, or TransactionService::updatePaymentStatus).
     *
     * Applies to both customer/online (`requested`) and staff cash (`pending`)
     * bookings. Tries to create the real GHL calendar booking when missing,
     * but ALWAYS flips local status to `confirmed` once payment is paid —
     * a GHL outage must not leave the booking stuck as Paid + requested.
     *
     * Idempotent / webhook-safe: no-op when already confirmed or cancelled.
     */
    public function autoConfirmAfterPayment(Booking $booking): Booking
    {
        if (in_array($booking->status, ['confirmed', 'cancelled'], true)) {
            return $booking;
        }

        if (! in_array($booking->status, ['requested', 'pending'], true)) {
            return $booking;
        }

        // Guard against duplicate real GHL calendar bookings: this runs from
        // the InvoicePaid webhook, live invoice-status reconciliation, cash
        // pay, and payment-status updates — so it must tolerate being called
        // more than once for the same booking.
        if (! $booking->ghl_booking_id) {
            try {
                $this->ghlBookingService->createBooking($booking, recordPaymentAs: 'card');
                $booking = $booking->fresh() ?? $booking;
            } catch (\Exception $e) {
                // Payment is already collected — confirm locally anyway so the
                // booking never sticks at Paid + requested/pending in production
                // when GHL is briefly unreachable or the slot sync fails.
                Log::error('GHL calendar booking failed during auto-confirm after payment', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($booking->status !== 'confirmed') {
            $booking->update(['status' => 'confirmed']);
        }

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Marks a cash reservation as paid, triggered from the Bookings list's Pay
     * action. Every cash booking is created local-only (see $deferGhl in
     * create()), so this also syncs it to GHL for the first time when possible.
     * Payment-status update itself triggers autoConfirmAfterPayment(); we still
     * call it explicitly when the transaction was already paid so a stuck
     * Paid + pending/requested row can self-heal on a second Pay click.
     */
    public function payCash(Booking $booking): Booking
    {
        if (! $booking->ghl_booking_id) {
            try {
                $this->ghlBookingService->createBooking($booking, skipPaymentEmail: true);
            } catch (\Exception $e) {
                Log::error('GHL booking creation failed (cash pay retry)', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $booking = $booking->fresh() ?? $booking;
        }

        $transaction = $booking->transactions()->latest()->first();
        if ($transaction && $transaction->payment_status !== 'paid') {
            // updatePaymentStatus() also auto-confirms the linked booking.
            $this->transactionService->updatePaymentStatus($transaction, 'paid');
        } else {
            $this->autoConfirmAfterPayment($booking);
        }

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    public function updateStatus(Booking $booking, string $status): Booking
    {
        if ($booking->status === 'requested' && $status === 'confirmed') {
            throw new \InvalidArgumentException('Use the confirm action to confirm a requested booking.');
        }

        $booking->update(['status' => $status]);

        try {
            $this->ghlBookingService->updateBookingStatus($booking, $status);
        } catch (\Exception $e) {
            Log::error('GHL booking status update failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $booking->fresh()->load(['customer', 'product']);
    }

    /**
     * Record actual customer check-in/check-out times locally. Not synced to GHL.
     * Only allowed when the booking is confirmed and fully paid.
     */
    public function updateCheckInOut(Booking $booking, array $data): Booking
    {
        if ($booking->status !== 'confirmed') {
            throw new \InvalidArgumentException('Check-in/out can only be updated for confirmed bookings.');
        }

        if (! $booking->isPaid()) {
            throw new \InvalidArgumentException('Check-in/out can only be updated after payment is received.');
        }

        $checkIn = $data['check_in'] ?? null;
        $checkOut = $data['check_out'] ?? null;

        if ($checkIn && $checkOut && now()->parse($checkOut)->lt(now()->parse($checkIn))) {
            throw new \InvalidArgumentException('Check-out must be on or after check-in.');
        }

        $booking->update([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        return $booking->fresh()->load(['customer', 'product', 'productRental', 'transactions']);
    }

    /** Enforce the min/max stay length from the live GHL booking config. */
    private function assertDurationAllowed(GhlServiceDetail $detail, string $checkIn, string $checkOut): void
    {
        $nights = max((int) now()->parse($checkIn)->startOfDay()->diffInDays(now()->parse($checkOut)->startOfDay()), 1);
        $unit = $detail->durationUnit() ?? 'day';

        if ($detail->minDuration() && $nights < $detail->minDuration()) {
            throw new \InvalidArgumentException("Minimum stay is {$detail->minDuration()} {$unit}(s).");
        }

        if ($detail->maxDuration() && $nights > $detail->maxDuration()) {
            throw new \InvalidArgumentException("Maximum stay is {$detail->maxDuration()} {$unit}(s).");
        }
    }
}
