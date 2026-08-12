<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerArchive;
use App\Models\ProductTransaction;
use App\Models\RentalTransaction;
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

        // No active match — before creating a brand-new row, check whether
        // this email belongs to a previously archived customer (e.g. their
        // GHL contact was removed and they were archived, and they're now
        // booking/being added again). Restoring the original row instead of
        // creating a fresh one prevents a duplicate Customer and preserves
        // all of their prior booking/transaction history. See
        // restoreFromArchive()'s doc comment for why this is safe.
        if (! empty($data['email'])) {
            $archive = $this->findArchiveByEmail($tenantId, $data['email']);

            if ($archive) {
                $customer = $this->restoreFromArchive($archive, $data['ghl_contact_id'] ?? null);

                $patch = array_filter([
                    'name' => $data['name'] ?? null,
                    'phone' => $customer->phone ?: ($data['phone'] ?? null),
                    'address' => $customer->address ?: ($data['address'] ?? null),
                ]);

                if ($patch) {
                    $customer->update($patch);
                }

                return $customer;
            }
        }

        return Customer::create($data + ['tenant_id' => $tenantId, 'created_by' => $createdBy]);
    }

    /**
     * Case-insensitive, tenant-scoped lookup of an archived customer by
     * email — the single canonical match used everywhere a customer might
     * reappear (findOrCreate() above, and GhlService's bulk pull/webhook
     * handlers). Deliberately never matched on ghl_contact_id: a customer
     * removed and re-added in GHL gets a brand-new contact id, so matching
     * on the old id would never find them and would silently create a
     * duplicate Customer instead of restoring the original.
     */
    public function findArchiveByEmail(string $tenantId, string $email): ?CustomerArchive
    {
        return CustomerArchive::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }

    /**
     * Moves a customer out of the active `customers` table into the
     * customer_archives table — used when GHL's contact sync determines a
     * customer no longer exists in GHL (see
     * GhlService::softDeleteMissingCustomers()). The underlying Customer row
     * is only ever soft-deleted (it already uses SoftDeletes), never
     * hard-deleted: Booking.customer_id is NOT NULL with onDelete('cascade'),
     * so a real hard delete here would silently destroy this customer's
     * entire booking history. Soft-deleting already achieves "removed from
     * the active customers table" for every application query (nothing in
     * this codebase ever calls withTrashed() on Customer outside this
     * archive/restore flow), while keeping every
     * Booking/RentalTransaction/ProductTransaction foreign key intact for a
     * later restore.
     *
     * Upserts by tenant+email (via findArchiveByEmail()) rather than always
     * inserting, so a customer archived, restored, and archived again never
     * accumulates more than one archive row for the same email — the
     * customer_archives_tenant_email_unique index enforces this at the DB
     * level too. A customer with no email is keyed by customer_id instead
     * (can never collide on email, so no dedup risk either way).
     */
    public function archiveCustomer(Customer $customer, string $reason = 'ghl_removed'): void
    {
        DB::transaction(function () use ($customer, $reason) {
            $attributes = [
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'ghl_contact_id' => $customer->ghl_contact_id,
                'ghl_sync_status' => $customer->ghl_sync_status,
                'ghl_last_synced_at' => $customer->ghl_last_synced_at,
                'created_by' => $customer->created_by,
                'original_created_at' => $customer->created_at,
                'archived_at' => now(),
                'archived_reason' => $reason,
            ];

            $existingArchive = $customer->email
                ? $this->findArchiveByEmail($customer->tenant_id, $customer->email)
                : CustomerArchive::where('tenant_id', $customer->tenant_id)
                    ->where('customer_id', $customer->id)
                    ->first();

            if ($existingArchive) {
                $existingArchive->update($attributes);
            } else {
                CustomerArchive::create($attributes);
            }

            // Only after the archive row is safely persisted — never remove
            // the customer from the active table on a failed archive write.
            $customer->delete();
        });
    }

    /**
     * Restores a previously archived customer back into the active
     * `customers` table, matched by CustomerArchive::$customer_id (the
     * common case — the original row, still physically present but
     * soft-deleted, is simply un-trashed, so every Booking/RentalTransaction
     * /ProductTransaction that already pointed at it resolves correctly with
     * zero reconstruction needed). $newGhlContactId is applied when the
     * restore was triggered by GHL sync (the customer's contact reappeared
     * under a new id there); it's left null for an in-app recreation
     * (findOrCreate()), so the caller's own subsequent syncContactToGhl()
     * call creates a fresh GHL contact rather than PUTing to the old,
     * now-deleted one.
     *
     * The archive row is always deleted afterward — restoring never leaves a
     * stale archive record behind, so the same customer can be archived and
     * restored again later without ever accumulating duplicates.
     */
    public function restoreFromArchive(CustomerArchive $archive, ?string $newGhlContactId = null): Customer
    {
        return DB::transaction(function () use ($archive, $newGhlContactId) {
            $customer = $archive->customer_id
                ? Customer::withTrashed()->find($archive->customer_id)
                : null;

            if ($customer) {
                if ($customer->trashed()) {
                    $customer->restore();
                }
            } else {
                // Rare fallback — the original row is genuinely gone (e.g.
                // hard-deleted through an unrelated path). Recreate from the
                // snapshot rather than losing the customer entirely; there's
                // no history to reattach since the original id no longer
                // exists.
                $customer = Customer::create([
                    'tenant_id' => $archive->tenant_id,
                    'name' => $archive->name,
                    'email' => $archive->email,
                    'phone' => $archive->phone,
                    'address' => $archive->address,
                    'created_by' => $archive->created_by,
                ]);
            }

            $customer->update([
                'ghl_contact_id' => $newGhlContactId,
                'ghl_sync_status' => $newGhlContactId ? 'synced' : 'not_synced',
                'ghl_last_synced_at' => $newGhlContactId ? now() : null,
            ]);

            $archive->delete();

            return $customer;
        });
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
                    'Failed to delete an upcoming booking from Lead Connector — customer was not deleted. '.$e->getMessage()
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
            // Customer no longer has a single transactions() relation — its
            // history now spans two independent tables (2026-08-10
            // transactions refactor). Neither has soft deletes, so a plain
            // delete() is already permanent — no withTrashed() needed.
            RentalTransaction::where('customer_id', $customer->id)->delete();
            ProductTransaction::where('customer_id', $customer->id)->delete(); // cascades to product_transaction_items via FK

            foreach ($bookings as $booking) {
                $booking->delete(); // Booking has no soft delete — already permanent.
            }

            // Defensive cleanup: route-model-binding on this endpoint already
            // excludes archived (soft-deleted) customers, so there's
            // normally nothing here — but a permanently-purged customer must
            // never leave a stale archive record behind, since a later
            // restore-by-email match against it would try to resurrect a
            // customer whose bookings/transactions were just deleted above.
            CustomerArchive::where('customer_id', $customer->id)->delete();

            $customer->forceDelete();
        });
    }
}
