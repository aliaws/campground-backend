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
     * Match an existing customer by email, then phone, scoped to a location, before creating.
     *
     * @param  ?string  $createdBy  Only applied when actually creating a new row (see
     *                              User::createdByLabel()) — never overwrites an existing
     *                              customer's original creator on a dedup match.
     */
    public function findOrCreate(array $data, string $locationId, ?string $createdBy = null): Customer
    {
        $customer = null;

        if (! empty($data['email'])) {
            $customer = Customer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            )
                ->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])
                ->first();
        }

        if (! $customer && ! empty($data['phone'])) {
            $customer = Customer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            )
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

            $customer->attachLocation($locationId);

            return $customer;
        }

        // No active match at this location — before creating a brand-new
        // row, check whether this email belongs to a previously archived
        // customer at this location (e.g. their GHL contact was removed and
        // they were archived, and they're now booking/being added again).
        // Restoring the original row instead of creating a fresh one
        // prevents a duplicate Customer and preserves all of their prior
        // booking/transaction history. See restoreFromArchive()'s doc
        // comment for why this is safe.
        if (! empty($data['email'])) {
            $archive = $this->findArchiveByEmail($locationId, $data['email']);

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

        $customer = Customer::create($data + ['created_by' => $createdBy]);
        $customer->attachLocation($locationId);

        return $customer;
    }

    /**
     * Case-insensitive lookup of an archived customer by email, scoped to a
     * single location — the single canonical match used everywhere a
     * customer might reappear (findOrCreate() above, and GhlService's bulk
     * pull/webhook handlers). Deliberately never matched on ghl_contact_id:
     * a customer removed and re-added in GHL gets a brand-new contact id,
     * so matching on the old id would never find them and would silently
     * create a duplicate Customer instead of restoring the original.
     */
    public function findArchiveByEmail(string $locationId, string $email): ?CustomerArchive
    {
        return CustomerArchive::where('engage_organization_location_id', $locationId)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }

    /**
     * Archives a customer's presence at ONE location — used when GHL's
     * contact sync for that location determines the customer no longer
     * exists there (see GhlService::softDeleteMissingCustomers()). Because
     * a Customer can belong to multiple locations (customers_locations
     * junction), "archived" here means "no longer linked to this location,"
     * not necessarily "gone everywhere": the customers_locations row for
     * $locationId is detached, and the underlying Customer row is only
     * soft-deleted if that was their last remaining location link. This
     * keeps a multi-location customer fully intact and visible at any other
     * location they still belong to, while still achieving "removed from
     * this location's active customers" immediately (index()/findOrCreate()
     * both scope through locationLinks, so a detached customer is instantly
     * invisible there) — and never breaks a Booking/RentalTransaction/
     * ProductTransaction foreign key, since the Customer row itself is
     * never hard-deleted.
     *
     * Upserts the archive snapshot by location+email (via
     * findArchiveByEmail()) rather than always inserting, so a customer
     * archived, restored, and archived again at the same location never
     * accumulates more than one archive row — the
     * customer_archives_location_email_unique index enforces this at the
     * DB level too. A customer with no email is keyed by customer_id
     * instead (can never collide on email, so no dedup risk either way).
     */
    public function archiveCustomer(Customer $customer, string $locationId, string $reason = 'ghl_removed'): void
    {
        DB::transaction(function () use ($customer, $locationId, $reason) {
            $attributes = [
                'engage_organization_location_id' => $locationId,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'ghl_contact_id' => $customer->ghlContactIdFor($locationId),
                'ghl_sync_status' => $customer->ghl_sync_status,
                'ghl_last_synced_at' => $customer->ghl_last_synced_at,
                'created_by' => $customer->created_by,
                'original_created_at' => $customer->created_at,
                'archived_at' => now(),
                'archived_reason' => $reason,
            ];

            $existingArchive = $customer->email
                ? $this->findArchiveByEmail($locationId, $customer->email)
                : CustomerArchive::where('engage_organization_location_id', $locationId)
                    ->where('customer_id', $customer->id)
                    ->first();

            if ($existingArchive) {
                $existingArchive->update($attributes);
            } else {
                CustomerArchive::create($attributes);
            }

            // Only after the archive row is safely persisted — never remove
            // the location link on a failed archive write.
            $customer->locationLinks()->where('engage_organization_location_id', $locationId)->delete();

            if ($customer->locationLinks()->doesntExist()) {
                $customer->delete();
            }
        });
    }

    /**
     * Restores a previously archived customer's link to the archive's
     * location, matched by CustomerArchive::$customer_id (the common
     * case — the original row, possibly still physically present, is
     * simply re-attached to the location, so every
     * Booking/RentalTransaction/ProductTransaction that already pointed at
     * it resolves correctly with zero reconstruction needed). If the
     * Customer row itself was soft-deleted (it had no other location left
     * when archived), it's restored first. $newGhlContactId is applied when
     * the restore was triggered by GHL sync (the customer's contact
     * reappeared under a new id there); it's left null for an in-app
     * recreation (findOrCreate()), so the caller's own subsequent
     * syncContactToGhl() call creates a fresh GHL contact rather than
     * PUTing to the old, now-deleted one.
     *
     * The archive row is always deleted afterward — restoring never leaves
     * a stale archive record behind, so the same customer can be archived
     * and restored again later without ever accumulating duplicates.
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
                    'name' => $archive->name,
                    'email' => $archive->email,
                    'phone' => $archive->phone,
                    'address' => $archive->address,
                    'created_by' => $archive->created_by,
                ]);
            }

            $customer->update([
                'ghl_sync_status' => $newGhlContactId ? 'synced' : 'not_synced',
                'ghl_last_synced_at' => $newGhlContactId ? now() : null,
            ]);

            $customer->attachLocation($archive->engage_organization_location_id, $newGhlContactId);

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
     * This is a full, every-location delete — unlike archiveCustomer()
     * (per-location), a staff-initiated hard delete removes the customer
     * everywhere, not just from one location.
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
                $booking->delete();
            }

            // Defensive cleanup: route-model-binding on this endpoint already
            // excludes archived (soft-deleted) customers, so there's
            // normally nothing here — but a permanently-purged customer must
            // never leave a stale archive record behind, since a later
            // restore-by-email match against it would try to resurrect a
            // customer whose bookings/transactions were just deleted above.
            CustomerArchive::where('customer_id', $customer->id)->delete();

            $customer->locationLinks()->delete();

            $customer->forceDelete();
        });
    }
}
