<?php

namespace App\Services;

use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageProduct;
use App\Models\EngageRentalTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * Replaces TransactionService for the rental/booking side (2026-08-10
 * transactions refactor). `rental_transactions` is now the sole source of
 * truth for every rental transaction — GHL pull/cron, POS bookings, and
 * customer-portal bookings all write here via this service.
 *
 * A rental booking's GHL invoice metadata (ghl_invoice_number/status/url)
 * lives on `Booking` itself, not on RentalTransaction — confirmed by
 * reading GhlBookingService::persistInvoiceMetadata() directly. This
 * service never touches that data; GhlBookingService's Booking-typed
 * methods (createBooking, createText2PayInvoice, recordInvoicePayment)
 * remain the sole owners of it, unchanged.
 */
class RentalTransactionService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
    ) {}

    /**
     * Columns the staff Campsite Transactions list can sort by (`sort_by`).
     * `record` is the list's default order (newest first) — the "#" column
     * is a row number, not a stored value.
     */
    private const SORTABLE = ['record', 'customer', 'campsite', 'booking', 'dates', 'amount', 'status', 'method', 'invoice', 'paid_at'];

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = EngageRentalTransaction::query();

        if (! empty($filters['engage_organization_location_id'])) {
            $query->where('engage_organization_location_id', $filters['engage_organization_location_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['booking_id'])) {
            $query->where('booking_id', $filters['booking_id']);
        }

        $this->applySort($query, $filters['sort_by'] ?? null, $filters['sort_dir'] ?? null);

        return $query->with(['customer.customerAccount', 'product', 'booking'])
            ->paginate($filters['per_page'] ?? 15);
    }

    private function applySort(Builder $query, mixed $sortBy, mixed $sortDir): void
    {
        $sortBy = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'record';
        $dir = $sortDir === 'asc' ? 'asc' : 'desc';
        $table = $query->getModel()->getTable();
        // Rows with no value (unpaid, no invoice, ...) always go last.
        $nullsLast = fn (string $column) => $query->orderByRaw("{$table}.{$column} {$dir} nulls last");

        match ($sortBy) {
            'customer' => $query->orderBy(
                EngageCustomer::withTrashed()->select('name')->whereColumn('id', "{$table}.customer_id")->limit(1),
                $dir
            ),
            'campsite' => $query->orderBy(
                EngageProduct::withTrashed()->select('name')->whereColumn('id', "{$table}.product_id")->limit(1),
                $dir
            ),
            // The booking reference shown in the list is derived from the booking id.
            'booking' => $nullsLast('booking_id'),
            'dates' => $query->orderByRaw("{$table}.check_in_date {$dir} nulls last, {$table}.check_out_date {$dir} nulls last"),
            'amount' => $query->orderBy("{$table}.amount", $dir),
            'status' => $query->orderBy("{$table}.status", $dir),
            'method' => $nullsLast('payment_method'),
            'invoice' => $nullsLast('ghl_invoice_id'),
            'paid_at' => $nullsLast('paid_at'),
            default => null,
        };

        // Always finish on creation time (+ id) so rows with equal sort
        // values keep a stable order from one page to the next.
        $tieDir = $sortBy === 'record' ? $dir : 'desc';
        $query->orderBy("{$table}.created_at", $tieDir)->orderBy("{$table}.id", $tieDir);
    }

    /**
     * Replaces TransactionService::autoCreateFromBooking(). Called from
     * BookingService::create() (online/Text2Pay branch), BookingService::confirm(),
     * and BookingController::store() (cash/staff-immediate branch) — same
     * call sites as before, same "exactly one line per booking" shape
     * (amount = unit_price * quantity, mirroring the old TransactionItem
     * invariant). $status defaults to 'pending' (matching the old
     * always-pending-at-creation default); GhlFullSyncService's sync path
     * passes 'paid' directly since it already knows the invoice is paid,
     * avoiding a fake create-then-mark-paid two-step.
     */
    public function createFromBooking(EngageBooking $booking, string $paymentMethod = 'card', string $status = 'pending'): EngageRentalTransaction
    {
        $quantity = max((int) ($booking->quantity ?? 1), 1);
        $unitPrice = round((float) $booking->total_amount / $quantity, 2);

        return EngageRentalTransaction::create([
            'engage_organization_location_id' => $booking->engage_organization_location_id,
            'booking_id' => $booking->id,
            'ghl_invoice_id' => $booking->ghl_invoice_id,
            'ghl_booking_id' => $booking->ghl_booking_id,
            'customer_id' => $booking->customer_id,
            'customer_name' => $booking->customer?->name,
            'customer_email' => $booking->customer?->email,
            'product_id' => $booking->product_id,
            'product_rental_id' => $booking->product_rental_id,
            // Matches the existing GHL-sync convention (upsertPaidInvoice()'s
            // 'rental_name' => $primary->name, where $primary is the
            // ProductRental) — falls back to the listing Product's name for
            // legacy/local-only bookings with no product_rental_id.
            'rental_name' => $booking->productRental?->name ?? $booking->product?->name,
            'amount' => $booking->total_amount,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'check_in_date' => $booking->check_in_date,
            'check_out_date' => $booking->check_out_date,
            'paid_at' => $status === 'paid' ? now() : null,
        ])->load(['customer', 'product', 'booking']);
    }

    /**
     * Staff/API-initiated "mark paid" — records payment to GHL against the
     * booking's own invoice and auto-confirms the booking. Replaces the
     * booking branch of TransactionService::updatePaymentStatus() +
     * autoConfirmLinkedBooking() + syncGhlInvoicePayment(). Terminal-guarded
     * (throws if already paid) — called ONLY from BookingService::payCash(),
     * same as the method it replaces.
     */
    public function confirmPayment(EngageRentalTransaction $rentalTransaction): EngageRentalTransaction
    {
        if ($rentalTransaction->isPaid()) {
            throw new \InvalidArgumentException('This transaction is already paid — payment status cannot be changed.');
        }

        $rentalTransaction->update(['status' => 'paid', 'paid_at' => now()]);

        $this->syncGhlInvoicePayment($rentalTransaction);
        $this->autoConfirmLinkedBooking($rentalTransaction);

        return $rentalTransaction->fresh()->load(['customer', 'product', 'booking']);
    }

    /**
     * "GHL already told us it's paid" (webhook/reconcile-driven) — status
     * flip only, no GHL calls (recording payment again would be circular:
     * GHL just told us it's paid), no auto-confirm (the caller,
     * GhlService::markInvoiceStatus(), already makes its own separate
     * auto-confirm call once per booking — doing it here too would
     * duplicate/reorder that side effect across multiple transactions on
     * the same booking). Replaces the direct ->update() bulk-flip inside
     * GhlService::markInvoiceStatus().
     */
    public function syncPaidStatusFromGhl(EngageRentalTransaction $rentalTransaction): EngageRentalTransaction
    {
        if (! $rentalTransaction->isPaid()) {
            $rentalTransaction->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $rentalTransaction;
    }

    /**
     * Keeps a booking's RentalTransaction row(s) ghl_invoice_id in step with
     * the Booking's own — a cash booking's ghl_invoice_id is often still
     * null when its RentalTransaction is first created, and only becomes
     * known later (payCash()). Without this, a future GHL Pull Data run's
     * updateOrCreate(['engage_organization_location_id','ghl_invoice_id']) can't match this row
     * and would create a duplicate ledger entry instead of updating it.
     */
    public function syncGhlInvoiceIdFromBooking(EngageBooking $booking): void
    {
        if (! $booking->ghl_invoice_id) {
            return;
        }

        EngageRentalTransaction::where('booking_id', $booking->id)
            ->where(function ($q) use ($booking) {
                $q->whereNull('ghl_invoice_id')->orWhere('ghl_invoice_id', '!=', $booking->ghl_invoice_id);
            })
            ->update(['ghl_invoice_id' => $booking->ghl_invoice_id, 'ghl_booking_id' => $booking->ghl_booking_id]);
    }

    private function syncGhlInvoicePayment(EngageRentalTransaction $rentalTransaction): void
    {
        $rentalTransaction->loadMissing('booking');

        $booking = $rentalTransaction->booking;
        if (! $booking?->ghl_invoice_id) {
            return;
        }

        try {
            $amount = (float) $rentalTransaction->amount + (float) ($booking->security_deposit_amount ?? 0);

            $this->ghlBookingService->recordInvoicePayment(
                $booking,
                $amount,
                (string) $rentalTransaction->payment_method,
            );
        } catch (\Exception $e) {
            Log::error('Lead Connector invoice payment recording failed', [
                'rental_transaction_id' => $rentalTransaction->id,
                'booking_id' => $booking->id,
                'ghl_invoice_id' => $booking->ghl_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function autoConfirmLinkedBooking(EngageRentalTransaction $rentalTransaction): void
    {
        $rentalTransaction->loadMissing('booking');
        $booking = $rentalTransaction->booking;

        if (! $booking || ! in_array($booking->status, ['requested', 'pending'], true)) {
            return;
        }

        try {
            app(BookingService::class)->autoConfirmAfterPayment($booking);
        } catch (\Exception $e) {
            Log::error('Auto-confirm after payment status update failed', [
                'rental_transaction_id' => $rentalTransaction->id,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
