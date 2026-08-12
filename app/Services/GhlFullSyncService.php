<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EngageToken;
use App\Models\GhlSyncLog;
use App\Models\Product;
use App\Models\ProductRental;
use App\Models\ProductTransaction;
use App\Models\RentalTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the "Pull Data" full sync (Engage Settings page + the daily
 * `ghl:sync-all` command): Contacts, Categories, Products, Services/Rentals
 * via the existing, unmodified pull services, plus two new phases (paid
 * invoices → RentalTransaction/ProductTransaction, and reconciling the
 * app's own already-known Booking/RentalTransaction/ProductTransaction
 * rows). As of the 2026-08-10 transactions refactor, RentalTransaction/
 * ProductTransaction are also the sole source of truth for every
 * locally-created transaction (POS, customer portal) — this file's own
 * writes to them were already correct going in, since the ledger design
 * always matched what the live app needed once payment_method/booking_id/
 * relational items were added to support both roles.
 *
 * Every phase runs in its own try/catch and records into `phase_errors` —
 * one phase failing never aborts the rest. The whole method is additionally
 * wrapped so the GhlSyncLog row is always finalized, even on a totally
 * unexpected failure.
 */
class GhlFullSyncService
{
    /**
     * The List Invoices endpoint requires `Version: v3` per GHL's own
     * published API reference (marketplace.gohighlevel.com/docs/ghl/
     * invoices/list-invoices) — confirmed 2026-08-07 by reading the live
     * docs directly (request/response schema, required headers and query
     * params), not guessed. This is a different, newer version than the
     * `2021-07-28`/`GhlClient::BOOKING_API_VERSION` ('2021-04-15') strings
     * used elsewhere in this codebase for *other* invoice/booking endpoints
     * (create/send/record-payment/cancel) — those are untouched here since
     * changing a shared constant would risk regressing them.
     *
     * There is no equivalent booking-list version constant — the
     * documented `GET calendars/services/bookings` v3 endpoint was tried
     * first (see git history around 2026-08-07) but consistently returned
     * zero results for this account's rental bookings, so
     * fetchCalendarBookingIndex() below uses a different, undocumented
     * `backend.leadconnectorhq.com` endpoint instead (no version header
     * forced, matching the convention of the other backend-host calls in
     * `GhlBookingService`).
     */
    private const INVOICE_API_VERSION = 'v3';

    private const MAX_INVOICE_PAGES = 200; // safety cap: 200 * 100 = 20,000 invoices

    public function __construct(
        private GhlClient $client,
        private GhlService $ghlService,
        private GhlProductSyncService $productSyncService,
        private GhlServiceSyncService $serviceSyncService,
        private RentalTransactionService $rentalTransactionService,
        private ProductTransactionService $productTransactionService,
    ) {}

    public function pullAll(string $tenantId, string $triggeredBy = 'manual'): GhlSyncLog
    {
        $log = GhlSyncLog::create([
            'engage_organization_location_id' => $tenantId,
            'status' => 'success',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        $start = microtime(true);
        $counts = [
            'total_contacts_pulled' => 0,
            'total_categories_pulled' => 0,
            'total_service_categories_pulled' => 0,
            'total_products_pulled' => 0,
            'total_services_pulled' => 0,
            'total_rentals_pulled' => 0,
            'total_paid_bookings_pulled' => 0,
            'total_paid_invoices_pulled' => 0,
        ];
        $phaseErrors = [];

        try {
            $this->runPhase('contacts', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->ghlService->bulkPullContacts($tenantId);
                $counts['total_contacts_pulled'] = $result['pulled'] ?? 0;

                return $result;
            });

            $this->runPhase('categories', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->productSyncService->pullCategoriesFromGhl($tenantId);
                $counts['total_categories_pulled'] = $result['pulled'] ?? 0;

                return $result;
            });

            $this->runPhase('products', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->productSyncService->bulkPullFromGhl($tenantId);
                $counts['total_products_pulled'] = $result['pulled'] ?? 0;

                return $result;
            });

            // Runs before 'services' (not before 'products', to avoid
            // reordering the pre-existing contacts→categories→products
            // sequence) — each rental's `service_category_id` is a raw GHL
            // id captured during the 'services' phase below; the local
            // ServiceCategory rows this phase creates/updates must already
            // exist for that id to resolve to a name anywhere it's
            // displayed/filtered on afterward.
            $this->runPhase('service_categories', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->serviceSyncService->pullServiceCategories($tenantId);
                $counts['total_service_categories_pulled'] = $result['pulled'] ?? 0;

                return $result;
            });

            $this->runPhase('services', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->serviceSyncService->pullServices($tenantId);
                // "Services" = distinct rental listings (base rows); "Rentals" =
                // every bookable unit under them, base + variants combined
                // (`pulled`, the pre-existing total) — NOT `variants_pulled`
                // alone. A listing with no separate variants still has one
                // bookable unit (its base row), so reporting `variants_pulled`
                // here previously showed "Rentals: 0" even when paid bookings
                // existed for those base-only listings, which read as broken.
                $counts['total_services_pulled'] = $result['base_listings_pulled'] ?? 0;
                $counts['total_rentals_pulled'] = $result['pulled'] ?? 0;

                return $result;
            });

            $this->runPhase('paid_invoices', $phaseErrors, function () use ($tenantId, &$counts) {
                $result = $this->pullPaidInvoices($tenantId);
                $counts['total_paid_bookings_pulled'] = $result['bookings'];
                $counts['total_paid_invoices_pulled'] = $result['invoices'];

                return $result;
            });

            $this->runPhase('reconcile_existing_records', $phaseErrors, function () use ($tenantId) {
                return $this->reconcileExistingRecords($tenantId);
            });
        } catch (\Throwable $e) {
            // Nothing above throws directly (every phase is wrapped by
            // runPhase()), but this guarantees the log is still finalized
            // even on a totally unexpected failure (e.g. DB connection loss).
            Log::error('GhlFullSyncService::pullAll fatal error', [
                'engage_organization_location_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $phaseErrors['fatal'] = $e->getMessage();
        }

        $status = 'success';
        $errorMessage = null;
        if (! empty($phaseErrors)) {
            $status = count($phaseErrors) >= 5 ? 'failed' : 'partial';
            $errorMessage = collect($phaseErrors)
                ->map(fn ($err, $phase) => "{$phase}: ".(is_array($err) ? json_encode($err) : $err))
                ->implode(' | ');
        }

        $log->update($counts + [
            'status' => $status,
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'error_message' => $errorMessage,
            'phase_errors' => $phaseErrors ?: null,
        ]);

        return $log->fresh();
    }

    private function runPhase(string $name, array &$phaseErrors, \Closure $callback): void
    {
        try {
            $result = $callback();

            if (is_array($result) && ! empty($result['error_details'])) {
                $phaseErrors[$name] = $result['error_details'];
            }
        } catch (\Throwable $e) {
            Log::error("GhlFullSyncService: {$name} phase failed", ['error' => $e->getMessage()]);
            $phaseErrors[$name] = $e->getMessage();
        }
    }

    /**
     * Paginates GHL's invoice list, filters to paid invoices, and routes
     * each into RentalTransaction and/or ProductTransaction depending on
     * what its line items resolve to locally. An invoice matching neither a
     * local rental nor a local product is skipped (not an error — it just
     * hasn't been pulled locally, e.g. via the products/services phases
     * above, or references something outside this tenant's catalog).
     *
     * @return array{invoices: int, bookings: int, error_details: array}
     */
    private function pullPaidInvoices(string $tenantId): array
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $bookingIndexResult = $this->fetchCalendarBookingIndex($locationId);
        $bookingIndex = $bookingIndexResult['index'];

        $invoiceCount = 0;
        $bookingCount = 0;
        $errors = [];

        // Surfaced into this phase's error_details (visible in the sync log's
        // phase_errors / the Engage Settings stats panel) rather than only
        // ever landing in the server log file — previously this failure was
        // completely invisible from the UI, which made "why don't real
        // check-in/out dates show up" impossible to diagnose without shell
        // access to the server. Non-fatal: the invoice-driven pull below
        // still runs and still creates RentalTransaction/ProductTransaction
        // rows regardless of whether this succeeded.
        if ($bookingIndexResult['error']) {
            $errors[] = ['source' => 'calendar_booking_enrichment', 'error' => $bookingIndexResult['error']];
        }

        $offset = 0;
        $limit = 100;
        $page = 0;

        do {
            try {
                // `status=paid` is a real, documented server-side filter
                // (confirmed against GHL's own List Invoices API reference)
                // — GHL only ever returns already-paid invoices, so pages of
                // draft/sent/void invoices are never fetched or processed at
                // all, rather than being fetched and discarded client-side.
                $response = $this->client->get('invoices/', [
                    'altId' => $locationId,
                    'altType' => 'location',
                    'status' => 'paid',
                    'limit' => $limit,
                    'offset' => $offset,
                ], self::INVOICE_API_VERSION);
            } catch (\Exception $e) {
                $errors[] = ['page' => $page, 'error' => 'Invoice list fetch failed: '.$e->getMessage()];
                Log::error('GHL invoice list fetch failed', ['offset' => $offset, 'error' => $e->getMessage()]);
                break;
            }

            $batch = $response['invoices'] ?? $response['data'] ?? [];

            foreach ($batch as $invoice) {
                // Defensive double-check even with the server-side filter
                // above — never trust a single layer for "only paid data
                // gets synced."
                if (($invoice['status'] ?? null) !== 'paid') {
                    continue;
                }

                try {
                    $outcome = $this->upsertPaidInvoice($tenantId, $invoice, $bookingIndex);
                    if ($outcome['rental']) {
                        $bookingCount++;
                    }
                    if ($outcome['product']) {
                        $invoiceCount++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'invoice_id' => $invoice['_id'] ?? $invoice['id'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('GHL paid invoice pull failed', [
                        'invoice_id' => $invoice['_id'] ?? $invoice['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $offset += $limit;
            $page++;
            usleep(100000);
        } while (count($batch) === $limit && $page < self::MAX_INVOICE_PAGES);

        return ['invoices' => $invoiceCount, 'bookings' => $bookingCount, 'error_details' => $errors];
    }

    /**
     * @return array{rental: bool, product: bool}
     */
    private function upsertPaidInvoice(string $tenantId, array $invoice, array $bookingIndex): array
    {
        $ghlInvoiceId = $invoice['_id'] ?? $invoice['id'] ?? null;

        if (! $ghlInvoiceId) {
            return ['rental' => false, 'product' => false];
        }

        $contact = $invoice['contactDetails'] ?? [];
        $customer = $this->resolveLocalCustomer($tenantId, $contact);

        // Only invoices/bookings whose contact is already a known local
        // Customer (i.e. already pulled into our Contacts via the contacts
        // phase, which always runs first in the same pullAll()) are synced.
        // A contact GHL knows about but we haven't pulled locally yet is
        // skipped entirely — no RentalTransaction/ProductTransaction row is
        // created for it — rather than falling back to a name/email
        // snapshot as before. This adds no extra query (resolveLocalCustomer()
        // already ran for every invoice) and actually skips the line-item
        // matching work below for anything that would be discarded anyway.
        if (! $customer) {
            Log::info('Skipping GHL invoice — contact not found in local Contacts', [
                'ghl_invoice_id' => $ghlInvoiceId,
                'ghl_contact_id' => $contact['id'] ?? null,
                'contact_email' => $contact['email'] ?? null,
            ]);

            return ['rental' => false, 'product' => false];
        }

        $customerName = $customer->name;
        $customerEmail = $customer->email;

        $amount = (float) ($invoice['total'] ?? $invoice['invoiceTotal'] ?? 0);
        $paidAt = $this->parseGhlDate($invoice['updatedAt'] ?? $invoice['issueDate'] ?? null);

        $rentalLines = [];
        $productLines = [];

        foreach ($invoice['invoiceItems'] ?? [] as $item) {
            $productId = $item['productId'] ?? null;
            $name = $item['name'] ?? null;

            // product_rentals has no location column of its own — scoped via
            // its product relationship (see GhlServiceSyncService's
            // identical pattern).
            $rental = $productId ? ProductRental::whereHas(
                'product',
                fn ($q) => $q->where('engage_organization_location_id', $tenantId)
            )->where('ghl_product_id', $productId)->first() : null;

            $product = null;
            if (! $rental) {
                $product = $productId
                    ? Product::where('engage_organization_location_id', $tenantId)->where('ghl_product_id', $productId)->whereNull('product_rental_id')->first()
                    : null;
            }

            // No productId on the line (seen on some Text2Pay booking invoices)
            // — fall back to an exact, case-insensitive name match.
            if (! $rental && ! $product && ! $productId && $name) {
                $rental = ProductRental::whereHas(
                    'product',
                    fn ($q) => $q->where('engage_organization_location_id', $tenantId)->whereRaw('LOWER(name) = ?', [strtolower($name)])
                )->first();

                if (! $rental) {
                    $product = Product::where('engage_organization_location_id', $tenantId)
                        ->whereNull('product_rental_id')
                        ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                        ->first();
                }
            }

            if ($rental) {
                $rentalLines[] = ['item' => $item, 'rental' => $rental];
            } elseif ($product) {
                $productLines[] = ['item' => $item, 'product' => $product];
            }
        }

        $wroteRental = false;
        $wroteProduct = false;

        if (! empty($rentalLines)) {
            $primary = $rentalLines[0]['rental'];
            $rentalAmount = empty($productLines) ? $amount : $this->sumLineAmounts($rentalLines);
            $ghlBookingMatch = $bookingIndex[$ghlInvoiceId] ?? $this->matchBookingByContactAndProduct($bookingIndex, $contact['id'] ?? null, $primary->ghl_product_id);

            $localBooking = $this->resolveLocalBooking($tenantId, $ghlInvoiceId, $ghlBookingMatch, $customer, $primary, $rentalAmount, $invoice);

            // Per an explicit requirement: a GHL rental invoice is only ever
            // imported into RentalTransaction when it can be matched to a
            // real Booking in *our own* system (either one that already
            // exists, or a brand-new one this call just created from real
            // GHL calendar data). An invoice with no such match is skipped
            // entirely — no RentalTransaction row is written for it, and any
            // row previously written for it under the old, looser rule is
            // left as-is here (a one-time cleanup of pre-existing unmatched
            // rows is handled separately, not on every sync run).
            if (! $localBooking) {
                Log::info('Skipping GHL rental invoice — no matching booking in our system', [
                    'ghl_invoice_id' => $ghlInvoiceId,
                    'product_rental_id' => $primary->id,
                ]);
            } else {
                RentalTransaction::updateOrCreate(
                    ['engage_organization_location_id' => $tenantId, 'ghl_invoice_id' => $ghlInvoiceId],
                    array_merge([
                        'booking_id' => $localBooking->id,
                        'ghl_booking_id' => $localBooking->ghl_booking_id,
                        'customer_id' => $customer->id,
                        'customer_name' => $customerName,
                        'customer_email' => $customerEmail,
                        'product_id' => $primary->product_id,
                        'product_rental_id' => $primary->id,
                        'rental_name' => $primary->name,
                        'amount' => $rentalAmount,
                        'status' => 'paid',
                        'check_in_date' => $localBooking->check_in_date,
                        'check_out_date' => $localBooking->check_out_date,
                        'notes' => null,
                        'paid_at' => $paidAt,
                        'raw_payload' => $invoice,
                    ], $localBooking->wasRecentlyCreated ? [
                        // Only backfill these when the Booking was just
                        // created purely from GHL sync data (necessarily an
                        // online/GHL-processed payment). A pre-existing
                        // Booking's own RentalTransaction row already carries
                        // its own correct payment_method/quantity/unit_price
                        // (set at real booking-creation time via
                        // RentalTransactionService::createFromBooking()) —
                        // overwriting them here would silently corrupt e.g. a
                        // cash payment into 'card'.
                        'payment_method' => 'card',
                        'quantity' => 1,
                        'unit_price' => $rentalAmount,
                    ] : [])
                );
                $wroteRental = true;
            }
        }

        if (! empty($productLines)) {
            $productAmount = empty($rentalLines) ? $amount : $this->sumLineAmounts($productLines);

            // `issueDate` is GHL's actual "Invoice Date" field — confirmed
            // via a live `GET invoices/` call against this tenant's real
            // account (2026-08-10), not guessed. Deliberately a *separate*
            // date field from `$paidAt` above (which stays untouched,
            // `updatedAt ?? issueDate`, unchanged for both branches) —
            // `invoice_date` always means "when the invoice was issued",
            // never "when it was paid". Product-only: `rental_transactions`
            // has no `invoice_date` column and this pass doesn't add one —
            // out of scope per explicit direction not to touch anything
            // rental-related.
            $invoiceDate = $this->parseGhlDate($invoice['issueDate'] ?? null);

            $productTransaction = ProductTransaction::updateOrCreate(
                ['engage_organization_location_id' => $tenantId, 'ghl_invoice_id' => $ghlInvoiceId],
                [
                    'customer_id' => $customer->id,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'amount' => $productAmount,
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                    'invoice_date' => $invoiceDate,
                    // `invoiceNumber`/`status` are real fields on the same
                    // live invoice payload (confirmed above) that were
                    // previously only ever captured for the *rental* side
                    // (Booking) — a synced product transaction's own
                    // ghl_invoice_number/status were left null, so its
                    // invoice() response fell back to a computed
                    // `INV-{date}-{id}` instead of GHL's real number.
                    // Backfilled here; `ghl_invoice_url` is deliberately
                    // left alone — GHL's invoice-get response carries no
                    // hosted-payment-link field (confirmed against the same
                    // live payload), that URL only ever comes from the
                    // separate Text2Pay response captured at local-creation
                    // time (see ProductTransactionService::create()).
                    'ghl_invoice_number' => $invoice['invoiceNumber'] ?? null,
                    'ghl_invoice_status' => $invoice['status'] ?? 'paid',
                    'raw_payload' => $invoice,
                ]
            );

            // Relational items (product_transaction_items), not the legacy
            // JSON `items` column — delete-then-recreate on every sync run,
            // matching this file's existing idempotent-sync philosophy
            // elsewhere (e.g. finalizeListing()'s prune-then-rebuild pattern
            // in GhlServiceSyncService).
            $productTransaction->items()->delete();
            foreach ($productLines as $l) {
                $productTransaction->items()->create([
                    'product_id' => $l['product']->id,
                    'product_name_snapshot' => $l['item']['name'] ?? $l['product']->name,
                    'product_type' => 'physical',
                    'quantity' => $l['item']['qty'] ?? 1,
                    'unit_price' => $l['item']['amount'] ?? 0,
                ]);
            }

            $wroteProduct = true;
        }

        return ['rental' => $wroteRental, 'product' => $wroteProduct];
    }

    /**
     * Resolves the local `Booking` a paid GHL rental invoice belongs to —
     * either one that already exists (created through this app's own
     * booking flow, or by a prior sync run), or a brand-new one created here
     * from real GHL calendar appointment data. Returns `null` when neither
     * is possible, which the caller (`upsertPaidInvoice()`) uses to skip
     * importing the invoice into `RentalTransaction` entirely — per an
     * explicit requirement, a GHL rental invoice with no corresponding
     * Booking in *our own* system must never be pulled/imported, on either
     * the manual "Pull Data" button or the daily `ghl:sync-all` cron (both
     * call this same method via `pullAll()` → `pullPaidInvoices()` →
     * `upsertPaidInvoice()`, so there is only one place to fix).
     *
     * Deliberately conservative about creating a new Booking, same as the
     * `createLocalBookingIfMissing()` logic this replaces:
     * - **Never touches an existing Booking.** If one already matches this
     *   invoice/booking id — whether created through this app's own
     *   New Booking / customer flow (which already sets `ghl_invoice_id`/
     *   `ghl_booking_id` at creation time) or by a previous sync run — it's
     *   returned as-is. Only a genuinely new row is ever inserted; nothing
     *   is ever updated, so a booking created through the live app flow can
     *   never be silently overwritten by sync data.
     * - **Never fabricates check-in/out dates.** `bookings.check_in_date`/
     *   `check_out_date` are NOT NULL — if `$ghlBookingMatch` has no real
     *   `start`/`end` (the calendar-booking enrichment didn't find a
     *   match), this returns `null` rather than guessing — which is now
     *   also the trigger for skipping the RentalTransaction ledger write
     *   too (previously the ledger row was written regardless, with a
     *   "dates unavailable" note; that orphan-creating path is gone).
     * - **Simplified, not equivalent to a normally-created booking** —
     *   `base_amount`/`security_deposit_amount` breakdown isn't available
     *   from this data, so `base_amount = total_amount`, everything else
     *   defaults to 0/null. Pay/Check-in visibility on the frontend is
     *   driven entirely by the linked RentalTransaction's `status`
     *   (`isPaid()` in `EditCheckInOutModal.tsx`) and `status: 'confirmed'`
     *   — both set correctly by the caller, so the existing UI naturally
     *   shows "Paid" and enables Check-in with no frontend changes needed.
     */
    private function resolveLocalBooking(
        string $tenantId,
        string $ghlInvoiceId,
        ?array $ghlBookingMatch,
        Customer $customer,
        ProductRental $rental,
        float $amount,
        array $invoice,
    ): ?Booking {
        $ghlBookingId = $ghlBookingMatch['id'] ?? null;

        $existing = Booking::where('engage_organization_location_id', $tenantId)
            ->where(function ($q) use ($ghlInvoiceId, $ghlBookingId) {
                $q->where('ghl_invoice_id', $ghlInvoiceId);
                if ($ghlBookingId) {
                    $q->orWhere('ghl_booking_id', $ghlBookingId);
                }
            })
            ->first();

        if ($existing) {
            return $existing;
        }

        if (empty($ghlBookingMatch['start']) || empty($ghlBookingMatch['end'])) {
            Log::info('Cannot resolve a local Booking for this GHL rental invoice — no existing match and no real check-in/out dates available from GHL', [
                'ghl_invoice_id' => $ghlInvoiceId,
            ]);

            return null;
        }

        return DB::transaction(function () use ($tenantId, $ghlInvoiceId, $ghlBookingId, $ghlBookingMatch, $customer, $rental, $amount, $invoice) {
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'product_id' => $rental->product_id,
                'product_rental_id' => $rental->id,
                'check_in_date' => $ghlBookingMatch['start'],
                'check_out_date' => $ghlBookingMatch['end'],
                'quantity' => 1,
                'base_amount' => $amount,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'security_deposit_amount' => 0,
                'status' => 'confirmed',
                'ghl_booking_id' => $ghlBookingId,
                'ghl_invoice_id' => $ghlInvoiceId,
                'ghl_invoice_number' => $invoice['invoiceNumber'] ?? null,
                'ghl_invoice_status' => 'paid',
                'engage_organization_location_id' => $tenantId,
                'notes' => 'Synced from Lead Connector via Pull Data — this booking was made directly through Lead Connector, not through this app.',
                'created_by' => 'Lead Connector Sync',
            ]);

            Log::info('Created local Booking from synced Lead Connector rental invoice', [
                'booking_id' => $booking->id,
                'ghl_invoice_id' => $ghlInvoiceId,
            ]);

            return $booking;
        });
    }

    private function sumLineAmounts(array $lines): float
    {
        return collect($lines)->sum(fn ($l) => (float) ($l['item']['amount'] ?? 0) * (float) ($l['item']['qty'] ?? 1));
    }

    /** Never creates a Customer — only resolves against what's already local (contacts phase runs first in the same pullAll()). */
    private function resolveLocalCustomer(string $locationId, array $contact): ?Customer
    {
        $ghlContactId = $contact['id'] ?? null;
        $email = $contact['email'] ?? null;

        if ($ghlContactId) {
            $customer = Customer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)->where('ghl_contact_id', $ghlContactId)
            )->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($email) {
            return Customer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            )->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        }

        return null;
    }

    /**
     * Best-effort real calendar bookings, used only to backfill accurate
     * check-in/out dates and `ghl_booking_id` onto matched RentalTransaction
     * rows. Failure here never blocks the invoice-driven pull above — it
     * just means dates stay null with an explanatory note.
     *
     * `POST backend/calendars/events/{locationId}/search/` — NOT part of
     * GHL's published API reference (confirmed absent from
     * marketplace.gohighlevel.com/docs/ghl/calendars/*; the documented
     * `GET calendars/services/bookings` v3 endpoint was tried first and
     * consistently returned zero results for this account's rental
     * bookings). This is the same undocumented `backend.leadconnectorhq.com`
     * host `GhlBookingService::createBooking()` already relies on for
     * booking creation — i.e. what GHL's own dashboard/Calendars view calls
     * internally, confirmed working via a real captured request/response
     * (2026-08-07): filtering `appointmentMeta.eventType = rental` returns
     * `{ count, appointments: [...] }`, each appointment carrying real
     * `startTime`/`endTime` (ISO 8601), `contactId`, `serviceBookingId`
     * (the id `GhlBookingService::cancelBooking()`'s
     * `DELETE calendars/services/bookings/{id}` call expects — used here as
     * `ghl_booking_id` for that reason, not the Events-collection `id`),
     * and `paymentMeta.invoiceIds[]` — a direct, reliable link back to the
     * exact invoice(s) this appointment belongs to, so the index below is
     * now keyed by invoice id with no date-window guessing needed (unlike
     * the old, always-empty documented endpoint, this call takes no date
     * range at all — it returns every rental appointment on the location).
     * Same undocumented-surface risk as the create/cancel calls: no
     * versioning guarantee, could change or be blocked without notice.
     *
     * @return array{index: array<string, array{id: ?string, start: ?string, end: ?string, contactId: ?string, productId: ?string}>, error: ?string}
     *                                                                                                                                               `index` is keyed by invoice id (an appointment can carry more
     *                                                                                                                                               than one, so the same entry may be indexed under several
     *                                                                                                                                               keys); `productId` stays null here (not present on an
     *                                                                                                                                               appointment) — matchBookingByContactAndProduct()'s fallback
     *                                                                                                                                               therefore never fires for entries from this source, which is
     *                                                                                                                                               fine since the invoice-id match above already covers them.
     *                                                                                                                                               `error` is non-null on a hard failure (network/auth/4xx) OR
     *                                                                                                                                               when the call succeeded but returned zero appointments.
     */
    private function fetchCalendarBookingIndex(string $locationId): array
    {
        try {
            $response = $this->client->postToBackend(
                "calendars/events/{$locationId}/search/",
                [
                    'filters' => [
                        ['group' => 'AND', 'filters' => []],
                        ['group' => 'AND', 'filters' => [
                            ['field' => 'appointmentMeta.eventType', 'operator' => 'eq', 'value' => 'rental'],
                        ]],
                    ],
                ],
            );

            $appointments = $response['appointments'] ?? [];
            $index = [];

            foreach ($appointments as $appt) {
                $entry = [
                    'id' => $appt['serviceBookingId'] ?? $appt['id'] ?? null,
                    'start' => $this->parseGhlDate($appt['startTime'] ?? null)?->toDateString(),
                    'end' => $this->parseGhlDate($appt['endTime'] ?? null)?->toDateString(),
                    'contactId' => $appt['contactId'] ?? null,
                    'productId' => null,
                ];

                foreach ($appt['paymentMeta']['invoiceIds'] ?? [] as $invoiceId) {
                    if ($invoiceId) {
                        $index[$invoiceId] = $entry;
                    }
                }
                $index['_by_contact_product'][($entry['contactId'] ?? '').'|'.($entry['productId'] ?? '')][] = $entry;
            }

            if (empty($appointments)) {
                Log::info('GHL calendar booking search returned zero rental appointments', [
                    'location_id' => $locationId,
                ]);

                return ['index' => $index, 'error' => 'Call succeeded but returned 0 rental appointments — either no rental bookings exist yet in GHL, or this undocumented backend endpoint\'s behavior has changed.'];
            }

            return ['index' => $index, 'error' => null];
        } catch (\Exception $e) {
            Log::warning('GHL calendar booking search failed — RentalTransaction dates will be approximate', [
                'error' => $e->getMessage(),
            ]);

            return ['index' => [], 'error' => $e->getMessage()];
        }
    }

    private function matchBookingByContactAndProduct(array $bookingIndex, ?string $contactId, ?string $productId): ?array
    {
        if (! $contactId || ! $productId) {
            return null;
        }

        $matches = $bookingIndex['_by_contact_product']["{$contactId}|{$productId}"] ?? [];

        return $matches[0] ?? null;
    }

    private function parseGhlDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestampMs((int) $value) : Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Catches up any of our own Booking/Transaction rows whose paid status
     * hasn't reconciled yet (e.g. the InvoicePaid webhook never arrived) —
     * reuses GhlService's existing, already-proven reconcile methods
     * verbatim, no modification. This is on top of (not instead of) the new
     * RentalTransaction/ProductTransaction ledger above.
     */
    private function reconcileExistingRecords(string $tenantId): array
    {
        $pendingBookings = Booking::where('engage_organization_location_id', $tenantId)
            ->whereNotNull('ghl_invoice_id')
            ->where(function ($q) {
                $q->whereNull('ghl_invoice_status')->orWhere('ghl_invoice_status', '!=', 'paid');
            })
            ->get();

        $this->ghlService->reconcileInvoiceStatusBatch($pendingBookings);

        $pendingProductTransactions = ProductTransaction::where('engage_organization_location_id', $tenantId)
            ->whereNotNull('ghl_invoice_id')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'paid');
            })
            ->get();

        foreach ($pendingProductTransactions as $productTransaction) {
            $this->ghlService->reconcileProductTransactionInvoiceStatus($productTransaction);
        }

        return [
            'reconciled_bookings' => $pendingBookings->count(),
            'reconciled_transactions' => $pendingProductTransactions->count(),
        ];
    }

    /**
     * Used by the daily scheduled command to loop every configured location.
     * On this branch, per-location GHL credentials live on EngageToken
     * (engage_organization_location_id + access_token), not on EngageSetting
     * itself — EngageSetting only holds the shared OAuth app credentials
     * (client_id/client_secret), keyed by the historical `tenant_id` column,
     * which here means `oauth_state_key`, not a location.
     */
    public static function locationIdsWithEngageSettings(): array
    {
        return EngageToken::whereNotNull('engage_organization_location_id')
            ->whereNotNull('access_token')
            ->pluck('engage_organization_location_id')
            ->unique()
            ->values()
            ->all();
    }
}
