<?php

namespace App\Services;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\Product;
use App\Models\ProductRental;
use App\Models\Reservation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class ReservationService
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
        $query = Reservation::query();

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
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

        return $query->with(['customer', 'product', 'productRental', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Price a booking without creating it. Pricing rules, duration bounds and
     * the stock ceiling all come LIVE from GHL (via the gateway's short cache);
     * only the overlapping-reservation count is local. Never throws on
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

        $booked = (int) Reservation::where('product_rental_id', $rental->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->sum('quantity');

        return max($stock - $booked, 0);
    }

    /**
     * $data['product_id'] may be a products.id (default variant) or a
     * product_rentals.id (other variants) — resolved here; the reservation
     * always stores product_id = base listing + product_rental_id = variant,
     * plus a snapshot of the GHL booking times taken at creation.
     *
     * @param  bool  $autoConfirm  When true (staff-created, default), the reservation is
     *                             immediately synced to GHL. When false (guest-submitted), it's created as a
     *                             'requested' record with a Text2Pay invoice — see confirm() for what turns it real.
     */
    public function create(array $data, bool $autoConfirm = true): Reservation
    {
        $resolved = $this->resolver->resolve($data['product_id'], $data['tenant_id']);

        if (! $resolved) {
            throw new \InvalidArgumentException('Product must be a bookable service for reservations.');
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

        $reservation = Reservation::create(array_merge($data, [
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

        if ($autoConfirm) {
            try {
                $this->ghlBookingService->createBooking($reservation);
            } catch (\Exception $e) {
                Log::error('GHL booking creation failed', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                $this->ghlBookingService->createText2PayInvoice($reservation);
                $this->transactionService->autoCreateFromReservation($reservation);
            } catch (\Exception $e) {
                Log::error('GHL Text2Pay invoice creation failed', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reservation->fresh()->load(['customer', 'product', 'productRental']);
    }

    /**
     * Turns a guest-submitted 'requested' reservation into a real one: syncs the
     * contact to GHL, creates the GHL booking + invoice (which emails the payment
     * link), marks it confirmed, and creates the local transaction record. This is
     * the ONLY path that should ever move a reservation out of 'requested'.
     */
    public function confirm(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'requested') {
            throw new \InvalidArgumentException('Only requested reservations can be confirmed this way.');
        }

        $this->ghlBookingService->createBooking($reservation);
        $reservation->update(['status' => 'confirmed']);
        $this->transactionService->autoCreateFromReservation($reservation);

        return $reservation->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Auto-confirm a guest reservation whose Text2Pay invoice was just paid (called from
     * the GHL webhook handler, `GhlService::applyInvoiceStatus()`). Creates the real GHL
     * calendar booking — same as confirm() — but records the already-collected payment on
     * the booking's auto-generated invoice instead of emailing a duplicate payment request,
     * and does NOT create a second Transaction (the one from guest submission was already
     * marked paid by the webhook handler before this runs).
     *
     * No-op if the reservation isn't 'requested' anymore — keeps this safe to call from a
     * webhook, which may retry/redeliver.
     */
    public function autoConfirmAfterPayment(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'requested') {
            return $reservation;
        }

        $this->ghlBookingService->createBooking($reservation, skipPaymentEmail: true);
        $reservation->update(['status' => 'confirmed']);

        return $reservation->fresh()->load(['customer', 'product', 'transactions']);
    }

    public function updateStatus(Reservation $reservation, string $status): Reservation
    {
        if ($reservation->status === 'requested' && $status === 'confirmed') {
            throw new \InvalidArgumentException('Use the confirm action to confirm a requested reservation.');
        }

        $reservation->update(['status' => $status]);

        try {
            $this->ghlBookingService->updateBookingStatus($reservation, $status);
        } catch (\Exception $e) {
            Log::error('GHL booking status update failed', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $reservation->fresh()->load(['customer', 'product']);
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
