<?php

namespace App\Services;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageProduct;
use App\Models\EngageProductRental;
use App\Models\EngageRentalTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class BookingService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private RentalTransactionService $rentalTransactionService,
        private BookingPriceCalculator $priceCalculator,
        private GhlRentalGateway $gateway,
        private RentalResolver $resolver,
    ) {}

    /**
     * Columns the staff Bookings list can sort by (`sort_by`), mapped to
     * how each one is ordered. `record` is the list's own default order
     * (creation time) — the "Record #" column is a row number, not a stored
     * value, so sorting by it means sorting by when the booking was created.
     */
    private const SORTABLE = ['record', 'created', 'customer', 'campsite', 'dates', 'total', 'status', 'paid'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = EngageBooking::query();

        if (! empty($filters['engage_organization_location_id'])) {
            $query->where('engage_organization_location_id', $filters['engage_organization_location_id']);
        }

        // status / customer_ids / product_ids each accept one value, a
        // comma-separated list, or an array — so the Bookings page can keep
        // its multi-select filters as short, readable URL params.
        if ($statuses = $this->listFilter($filters['status'] ?? null)) {
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if ($customerIds = $this->listFilter($filters['customer_ids'] ?? null)) {
            $query->whereIn('customer_id', $customerIds);
        }

        if ($productIds = $this->listFilter($filters['product_ids'] ?? null)) {
            $query->whereIn('product_id', $productIds);
        }

        if (! empty($filters['date_from'])) {
            $query->where('check_in_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('check_out_date', '<=', $filters['date_to']);
        }

        // stay_from / stay_to (the Bookings page's date-range filter) match
        // any booking whose stay overlaps the range — unlike date_from /
        // date_to above, which require the stay to sit fully inside it.
        if (! empty($filters['stay_from'])) {
            $query->where('check_out_date', '>=', $filters['stay_from']);
        }

        if (! empty($filters['stay_to'])) {
            $query->where('check_in_date', '<=', $filters['stay_to']);
        }

        // When the booking was made (whole days, inclusive on both ends).
        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        // Same definition of "paid" the frontend's isPaid() uses: at least
        // one linked rental transaction with status 'paid'.
        $paid = fn ($q) => $q->where('status', 'paid');
        if (($filters['payment_status'] ?? null) === 'paid') {
            $query->whereHas('transactions', $paid);
        } elseif (($filters['payment_status'] ?? null) === 'unpaid') {
            $query->whereDoesntHave('transactions', $paid);
        }

        $this->applySort($query, $filters['sort_by'] ?? null, $filters['sort_dir'] ?? null);

        return $query->with(['customer.customerAccount', 'product.rentals', 'productRental', 'transactions', 'organizationLocation'])
            ->paginate($filters['per_page'] ?? 15);
    }

    /** Normalizes a single value, a comma-separated string, or an array into a clean list of strings. */
    private function listFilter(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $value
        ), fn ($v) => $v !== ''));
    }

    private function applySort(Builder $query, mixed $sortBy, mixed $sortDir): void
    {
        $sortBy = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'record';
        $dir = $sortDir === 'asc' ? 'asc' : 'desc';
        $bookings = $query->getModel()->getTable();

        match ($sortBy) {
            'customer' => $query->orderBy(
                EngageCustomer::withTrashed()->select('name')->whereColumn('id', "{$bookings}.customer_id")->limit(1),
                $dir
            ),
            'campsite' => $query->orderBy(
                EngageProduct::withTrashed()->select('name')->whereColumn('id', "{$bookings}.product_id")->limit(1),
                $dir
            ),
            'dates' => $query->orderBy('check_in_date', $dir)->orderBy('check_out_date', $dir),
            'total' => $query->orderBy('total_amount', $dir),
            'status' => $query->orderBy('status', $dir),
            'paid' => $query->orderBy(
                EngageRentalTransaction::selectRaw('count(*)')
                    ->whereColumn('booking_id', "{$bookings}.id")
                    ->where('status', 'paid'),
                $dir
            ),
            default => null,
        };

        // Always finish on creation time (+ id) so rows with equal sort
        // values keep a stable order from one page to the next.
        // ('created' is the Created At column — the same order as 'record'.)
        $tieDir = in_array($sortBy, ['record', 'created'], true) ? $dir : 'desc';
        $query->orderBy("{$bookings}.created_at", $tieDir)->orderBy("{$bookings}.id", $tieDir);
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
    public function quote(EngageProduct $product, EngageProductRental $rental, string $checkIn, string $checkOut, int $quantity = 1): array
    {
        $detail = $this->gateway->fetchRentalDetail($rental);

        return $this->quoteFromDetail($detail, $rental, $checkIn, $checkOut, $quantity);
    }

    private function quoteFromDetail(GhlServiceDetail $detail, EngageProductRental $rental, string $checkIn, string $checkOut, int $quantity): array
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
    public function remainingStock(EngageProductRental $rental, ?int $stock, string $checkIn, string $checkOut): ?int
    {
        if ($stock === null) {
            return null;
        }

        $booked = (int) EngageBooking::where('product_rental_id', $rental->id)
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
    public function create(array $data, bool $autoConfirm = true): EngageBooking
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

        $booking = EngageBooking::create(array_merge($data, [
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
                Log::error('Lead Connector booking creation failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                $this->ghlBookingService->createText2PayInvoice($booking);
                $this->rentalTransactionService->createFromBooking($booking);
                $this->rentalTransactionService->syncGhlInvoiceIdFromBooking($booking);
            } catch (\Exception $e) {
                Log::error('Lead Connector Text2Pay invoice creation failed', [
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
    public function confirm(EngageBooking $booking): EngageBooking
    {
        if ($booking->status !== 'requested') {
            throw new \InvalidArgumentException('Only requested bookings can be confirmed this way.');
        }

        $this->ghlBookingService->createBooking($booking);
        $booking->update(['status' => 'confirmed']);
        $this->rentalTransactionService->createFromBooking($booking);
        $this->rentalTransactionService->syncGhlInvoiceIdFromBooking($booking);

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Retries the Text2Pay invoice for a 'requested' ('card'/online) booking
     * that never got one — the known gap where create()'s else branch (see
     * above) swallows a GHL failure into a log line, leaving a booking that
     * came back 201 with no invoice/transaction and no way for the customer
     * to pay. Unlike confirm() above, this does NOT skip straight to a real
     * GHL calendar booking + 'confirmed' status — it retries the *original*
     * step (create the invoice, leave the booking 'requested' awaiting the
     * customer's online payment), exactly as if create() had succeeded the
     * first time.
     *
     * Guarded on no invoice already existing — createText2PayInvoice()
     * would otherwise create a genuine duplicate invoice for a booking that
     * already has one, and createFromBooking() has no dedupe of its own
     * (always inserts a new row).
     */
    public function retryInvoice(EngageBooking $booking): EngageBooking
    {
        if ($booking->status !== 'requested') {
            throw new \InvalidArgumentException('Only a requested booking can have its invoice retried.');
        }

        if ($booking->ghl_invoice_id || $booking->ghl_invoice_url) {
            throw new \InvalidArgumentException('This booking already has an invoice.');
        }

        $this->ghlBookingService->createText2PayInvoice($booking);
        $this->rentalTransactionService->createFromBooking($booking);
        $this->rentalTransactionService->syncGhlInvoiceIdFromBooking($booking);

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Staff "send the invoice again": re-emails the customer the existing
     * unpaid invoice (same pay link — no new invoice is created).
     */
    public function resendInvoice(EngageBooking $booking): EngageBooking
    {
        $booking->loadMissing(['transactions', 'customer']);

        if (! $booking->canResendInvoice()) {
            throw new \InvalidArgumentException('Only an unpaid booking with an open invoice can have its invoice sent again.');
        }

        if (! $booking->customer?->email) {
            throw new \InvalidArgumentException('This customer has no email address to send the invoice to.');
        }

        $this->ghlBookingService->resendInvoice($booking);

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Staff "void invoice": for an invoice that has gone unpaid for 24 hours
     * or more. Voids it in Lead Connector (its pay link stops working) and
     * cancels the booking, which frees the dates again.
     *
     * The void comes first and is not best-effort: if Lead Connector refuses
     * or can't be reached, nothing changes locally — otherwise the booking
     * would read "cancelled" here while the customer could still pay the
     * live invoice. Removing the calendar booking afterwards (only the
     * cash/pay-later flow has one before payment) is best-effort, the same
     * as every other cancellation.
     */
    public function voidInvoice(EngageBooking $booking): EngageBooking
    {
        $booking->loadMissing('transactions');

        if (! $booking->hasOpenUnpaidInvoice()) {
            throw new \InvalidArgumentException('Only an unpaid booking with an open invoice can be voided.');
        }

        if (! $booking->canVoidInvoice()) {
            throw new \InvalidArgumentException(
                'An invoice can only be voided once it has been unpaid for '.EngageBooking::voidInvoiceAfterHours().' hours or more.'
            );
        }

        $this->ghlBookingService->voidInvoice($booking);

        $booking->update(['status' => 'cancelled']);

        if ($booking->ghl_booking_id) {
            try {
                $this->ghlBookingService->cancelBooking($booking);
            } catch (\Exception $e) {
                Log::error('Lead Connector booking removal failed after voiding its invoice', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    /**
     * Auto-confirm a booking whose payment was just marked paid (webhook,
     * invoice reconciliation, cash pay, or RentalTransactionService::confirmPayment()).
     *
     * Applies to both customer/online (`requested`) and staff cash (`pending`)
     * bookings. Tries to create the real GHL calendar booking when missing,
     * but ALWAYS flips local status to `confirmed` once payment is paid —
     * a GHL outage must not leave the booking stuck as Paid + requested.
     *
     * Idempotent / webhook-safe: no-op when already confirmed or cancelled.
     */
    public function autoConfirmAfterPayment(EngageBooking $booking): EngageBooking
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
                Log::error('Lead Connector calendar booking failed during auto-confirm after payment', [
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
    public function payCash(EngageBooking $booking): EngageBooking
    {
        if (! $booking->ghl_booking_id) {
            try {
                $this->ghlBookingService->createBooking($booking, skipPaymentEmail: true);
            } catch (\Exception $e) {
                Log::error('Lead Connector booking creation failed (cash pay retry)', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $booking = $booking->fresh() ?? $booking;
            $this->rentalTransactionService->syncGhlInvoiceIdFromBooking($booking);
        }

        $transaction = $booking->transactions()->latest()->first();
        if ($transaction && ! $transaction->isPaid()) {
            // confirmPayment() also auto-confirms the linked booking.
            $this->rentalTransactionService->confirmPayment($transaction);
        } else {
            $this->autoConfirmAfterPayment($booking);
        }

        return $booking->fresh()->load(['customer', 'product', 'transactions']);
    }

    public function updateStatus(EngageBooking $booking, string $status): EngageBooking
    {
        if ($booking->status === 'requested' && $status === 'confirmed') {
            throw new \InvalidArgumentException('Use the confirm action to confirm a requested booking.');
        }

        $booking->update(['status' => $status]);

        try {
            $this->ghlBookingService->updateBookingStatus($booking, $status);
        } catch (\Exception $e) {
            Log::error('Lead Connector booking status update failed', [
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
    public function updateCheckInOut(EngageBooking $booking, array $data): EngageBooking
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
