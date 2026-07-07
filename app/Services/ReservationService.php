<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class ReservationService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private TransactionService $transactionService,
        private BookingPriceCalculator $priceCalculator,
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

        return $query->with(['customer', 'product', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Price a booking without creating it — applies the product's pricing rule
     * (seasonal overrides, day-of-week adjustments, duration/quantity discounts).
     */
    public function quote(Product $product, string $checkIn, string $checkOut, int $quantity = 1): array
    {
        if (! $product->isCampsite()) {
            throw new \InvalidArgumentException('Product must be a bookable service for reservations.');
        }

        $this->assertDurationAllowed($product, $checkIn, $checkOut);
        $this->assertAvailable($product, $checkIn, $checkOut, $quantity);

        return $this->priceCalculator->quote($product, $checkIn, $checkOut, $quantity);
    }

    /**
     * @param bool $autoConfirm When true (staff-created, default), the reservation is
     *   immediately confirmed and synced to GHL — today's exact behavior, unchanged.
     *   When false (guest-submitted), it's created as a 'requested' local-only record
     *   with no GHL booking/invoice/transaction — see confirm() for what turns it real.
     */
    public function create(array $data, bool $autoConfirm = true): Reservation
    {
        $product = Product::findOrFail($data['product_id']);
        $quantity = (int) ($data['quantity'] ?? 1);

        $quote = $this->quote($product, $data['check_in_date'], $data['check_out_date'], $quantity);

        $reservation = Reservation::create($data + [
            'quantity' => $quantity,
            'base_amount' => $quote['subtotal'],
            'discount_amount' => $quote['discount_amount'],
            'total_amount' => $quote['total_amount'],
            'security_deposit_amount' => $quote['security_deposit_amount'],
            'price_breakdown' => $quote,
            'status' => $autoConfirm ? 'pending' : 'requested',
        ]);

        if ($autoConfirm) {
            try {
                $this->ghlBookingService->createBooking($reservation);
            } catch (\Exception $e) {
                Log::error('GHL booking creation failed', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reservation->load(['customer', 'product']);
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

    /** Enforce the product's min/max stay length (GHL booking config). */
    private function assertDurationAllowed(Product $product, string $checkIn, string $checkOut): void
    {
        $nights = max((int) now()->parse($checkIn)->startOfDay()->diffInDays(now()->parse($checkOut)->startOfDay()), 1);
        $unit = $product->duration_unit ?? 'day';

        if ($product->min_duration && $nights < $product->min_duration) {
            throw new \InvalidArgumentException("Minimum stay is {$product->min_duration} {$unit}(s).");
        }

        if ($product->max_duration && $nights > $product->max_duration) {
            throw new \InvalidArgumentException("Maximum stay is {$product->max_duration} {$unit}(s).");
        }
    }

    /** Reject the booking when overlapping reservations exhaust the product's stock. */
    private function assertAvailable(Product $product, string $checkIn, string $checkOut, int $quantity): void
    {
        $stock = $product->available_quantity;

        if ($stock === null) {
            return;
        }

        $booked = (int) Reservation::where('product_id', $product->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->sum('quantity');

        if ($booked + $quantity > $stock) {
            $remaining = max($stock - $booked, 0);
            throw new \InvalidArgumentException(
                "Not available for the selected dates. Remaining quantity: {$remaining}."
            );
        }
    }
}
