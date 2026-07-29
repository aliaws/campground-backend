<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private GhlService $ghlService,
    ) {}

    /**
     * Match an existing customer by email, then phone, before creating a new one.
     *
     * @param  ?string  $createdBy  Only applied when actually creating a new row (see
     *                              User::createdByLabel()) — never overwrites an existing
     *                              customer's original creator on a dedup match.
     */
    public function findOrCreate(array $data, string $tenantId, ?string $createdBy = null): Customer
    {
        $customer = null;

        if (! empty($data['email'])) {
            $customer = Customer::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
                ->first();
        }

        if (! $customer && ! empty($data['phone'])) {
            $customer = Customer::where('tenant_id', $tenantId)
                ->where('phone', $data['phone'])
                ->first();
        }

        if ($customer) {
            $patch = array_filter([
                'email' => $customer->email ?: ($data['email'] ?? null),
                'phone' => $customer->phone ?: ($data['phone'] ?? null),
                'address' => $customer->address ?: ($data['address'] ?? null),
            ]);

            if ($patch) {
                $customer->update($patch);
            }

            return $customer;
        }

        return Customer::create($data + ['tenant_id' => $tenantId, 'created_by' => $createdBy]);
    }

    /**
     * Splits a customer's bookings into completed (check-out date already in
     * the past) vs upcoming (check-out date today or in the future) among
     * non-cancelled bookings, plus a separate cancelled bucket — used to
     * decide what a hard-delete confirmation should say, and (in
     * hardDelete()) which bookings need a GHL calendar deletion first.
     * Cancelled bookings are neither "completed" nor "upcoming": they're
     * still deleted locally like everything else, but never need a GHL call
     * (already cancelled there) and never block/require the "this will also
     * delete upcoming GHL bookings" confirmation.
     *
     * @return array{total: int, completed: Collection<int, Booking>, upcoming: Collection<int, Booking>, cancelled: Collection<int, Booking>}
     */
    public function classifyBookingsForDeletion(Customer $customer): array
    {
        $today = now()->startOfDay();
        $bookings = $customer->bookings()->with('product')->get();

        $cancelled = $bookings->where('status', 'cancelled')->values();
        $active = $bookings->where('status', '!=', 'cancelled');
        $completed = $active->filter(fn (Booking $b) => $b->check_out_date->lt($today))->values();
        $upcoming = $active->filter(fn (Booking $b) => ! $b->check_out_date->lt($today))->values();

        return [
            'total' => $bookings->count(),
            'completed' => $completed,
            'upcoming' => $upcoming,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Permanently deletes a customer and every local booking/transaction
     * record they have — a true hard delete, replacing Customer's normal
     * soft delete for this one destructive action. Upcoming (today-or-future,
     * non-cancelled) bookings with a real GHL calendar booking are deleted
     * from GHL FIRST, one at a time via GhlBookingService::cancelBooking()
     * (GHL's "delete service booking" endpoint — the same one booking
     * cancellation already uses elsewhere in this app). If ANY of those GHL
     * deletes fails, the whole operation aborts before anything local is
     * touched, so a customer is never left half-deleted with a real GHL
     * calendar event still pointing at a row that's about to disappear.
     * Already-completed (past) bookings are deliberately left untouched in
     * GHL — they remain there as historical record — only their local rows
     * are removed.
     *
     * @throws \RuntimeException if a required GHL booking deletion fails — nothing is deleted locally in that case
     */
    public function hardDelete(Customer $customer): void
    {
        $today = now()->startOfDay();
        $bookings = $customer->bookings()->get();

        $upcoming = $bookings->filter(
            fn (Booking $b) => $b->status !== 'cancelled' && ! $b->check_out_date->lt($today) && $b->ghl_booking_id
        );

        foreach ($upcoming as $booking) {
            try {
                $this->ghlBookingService->cancelBooking($booking);
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Failed to delete an upcoming booking from GoHighLevel — customer was not deleted. '.$e->getMessage()
                );
            }
        }

        // Existing, pre-established behavior (contact delete + portal login
        // teardown) — kept in the same order as the prior soft-delete flow.
        // GHL contact delete already fails open internally (catches/logs its
        // own errors), so it's safe to run before the transaction below.
        // CustomerAccountService is resolved lazily (not constructor-injected)
        // to avoid a circular dependency — it already depends on
        // CustomerService itself (see PublicBookingController's booking flow),
        // same pattern GhlService::markInvoiceStatus() uses for BookingService.
        $this->ghlService->deleteContactFromGhl($customer);
        app(CustomerAccountService::class)->deleteCustomerAccount($customer);

        DB::transaction(function () use ($customer, $bookings) {
            // withTrashed(): a customer's transactions might include rows
            // already soft-deleted by the normal Transaction flow — those
            // still need a real hard delete here, not to be left behind.
            $customer->transactions()->withTrashed()->get()->each(
                fn (Transaction $transaction) => $transaction->forceDelete()
            );

            foreach ($bookings as $booking) {
                $booking->delete(); // Booking has no soft delete — already permanent.
            }

            $customer->forceDelete();
        });
    }
}
