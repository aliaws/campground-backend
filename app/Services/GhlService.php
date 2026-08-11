<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EngageSetting;
use App\Models\ProductTransaction;
use App\Models\RentalTransaction;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class GhlService
{
    public function __construct(
        private GhlClient $client,
    ) {}

    public function syncContactToGhl(Customer $customer): ?string
    {
        $customer->update(['ghl_sync_status' => 'pending']);

        $locationId = $this->client->getLocationId();

        $nameParts = explode(' ', $customer->name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $sharedFields = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];

        if ($customer->address && is_array($customer->address)) {
            $addr = $customer->address;
            if (! empty($addr['line1'])) {
                $sharedFields['address1'] = $addr['line1'];
            }
            if (! empty($addr['city'])) {
                $sharedFields['city'] = $addr['city'];
            }
            if (! empty($addr['state'])) {
                $sharedFields['state'] = $addr['state'];
            }
            if (! empty($addr['postal_code'])) {
                $sharedFields['postalCode'] = $addr['postal_code'];
            }
            if (! empty($addr['country'])) {
                $sharedFields['country'] = $addr['country'];
            }
        }

        try {
            if ($customer->ghl_contact_id) {
                // PUT does not accept locationId
                $response = $this->client->put("contacts/{$customer->ghl_contact_id}", $sharedFields);

                $this->logOutbound('contact.updated', $sharedFields, $response);

                $customer->update([
                    'ghl_sync_status' => 'synced',
                    'ghl_last_synced_at' => now(),
                ]);

                return $customer->ghl_contact_id;
            }

            // POST requires locationId to identify the sub-account
            $createPayload = array_merge(['locationId' => $locationId], $sharedFields);
            $response = $this->client->post('contacts/', $createPayload);

            $this->logOutbound('contact.created', $createPayload, $response);

            $ghlId = $response['contact']['id']
                ?? $response['id']
                ?? $response['_id']
                ?? $response['data']['id']
                ?? $response['data']['_id']
                ?? null;

            if ($ghlId) {
                $customer->update([
                    'ghl_contact_id' => $ghlId,
                    'ghl_sync_status' => 'synced',
                    'ghl_last_synced_at' => now(),
                ]);
            }

            return $ghlId;
        } catch (\Exception $e) {
            // GHL returns 400 when a contact with the same phone/email already exists.
            // Extract the existing contact's ID from the error response and link it.
            $message = $e->getMessage();
            if (str_contains($message, 'duplicated contacts') || str_contains($message, '400')) {
                preg_match('/"contactId"\s*:\s*"([^"]+)"/', $message, $matches);
                $existingId = $matches[1] ?? null;

                if ($existingId) {
                    Log::info('GHL contact already exists, linking', [
                        'customer_id' => $customer->id,
                        'ghl_contact_id' => $existingId,
                    ]);

                    $customer->update([
                        'ghl_contact_id' => $existingId,
                        'ghl_sync_status' => 'synced',
                        'ghl_last_synced_at' => now(),
                    ]);

                    return $existingId;
                }
            }

            Log::error('GHL contact sync failed', [
                'customer_id' => $customer->id,
                'error' => $message,
            ]);

            $customer->update(['ghl_sync_status' => 'error']);

            $this->logOutbound('contact.sync_failed', $sharedFields, ['error' => $message]);
            throw $e;
        }
    }

    public function updateContactInGhl(Customer $customer): void
    {
        $this->syncContactToGhl($customer);
    }

    /**
     * Fetch all contacts from GHL and upsert them into the local customers table.
     * GHL paginates contacts; we loop until all pages are consumed.
     *
     * @return array{pulled: int, created: int, updated: int, errors: int, error_details: array}
     */
    public function bulkPullContacts(string $tenantId): array
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $results = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0, 'deactivated' => 0, 'error_details' => []];
        $page = 0;
        $limit = 100;
        $total = null;
        $seenGhlContactIds = [];
        // Only true once every page has been fetched with no exception —
        // see softDeleteMissingCustomers()'s doc comment for why this must
        // gate the removal pass. A per-page fetch failure `break`s the loop
        // below and this stays false, so a partial contact list (we only
        // ever saw pages 1-2 of, say, 5) is never mistaken for "GHL's
        // complete current contact list" and used to soft-delete every
        // customer whose page we simply never reached.
        $fetchComplete = true;

        do {
            try {
                $response = $this->client->get('contacts/', [
                    'locationId' => $locationId,
                    'limit' => $limit,
                    'page' => $page,
                ]);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = ['page' => $page, 'error' => $e->getMessage()];
                $fetchComplete = false;
                break;
            }

            $contacts = $response['contacts'] ?? [];

            foreach ($contacts as $contact) {
                if (! empty($contact['id'])) {
                    // Recorded regardless of whether the local save below
                    // succeeds — what matters for delete-sync purposes is
                    // "does GHL still have this contact," independent of
                    // any local persistence failure.
                    $seenGhlContactIds[] = $contact['id'];
                }

                try {
                    $name = trim(
                        ($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')
                    ) ?: ($contact['name'] ?? 'Unknown');

                    // A row soft-deleted by a prior delete-sync run
                    // (softDeleteMissingCustomers() below) whose contact has
                    // reappeared in GHL needs restoring first —
                    // ghl_contact_id has no unique DB constraint, so without
                    // this, updateOrCreate() below would find no *visible*
                    // match (soft-deleted rows are excluded from default
                    // queries) and silently create a genuine duplicate
                    // Customer row for the same contact.
                    $trashedMatch = Customer::onlyTrashed()->where('ghl_contact_id', $contact['id'])->first();
                    $trashedMatch?->restore();

                    $customer = Customer::updateOrCreate(
                        ['ghl_contact_id' => $contact['id']],
                        [
                            'name' => $name,
                            'email' => $contact['email'] ?? null,
                            'phone' => $contact['phone'] ?? null,
                            'ghl_sync_status' => 'synced',
                            'ghl_last_synced_at' => now(),
                            'tenant_id' => $tenantId,
                        ]
                    );

                    if ($customer->wasRecentlyCreated) {
                        $results['created']++;
                        // Only on genuine creation — never overwrites an
                        // already-attributed customer's real created_by
                        // (e.g. staff-added or self-registered via public
                        // booking) just because a later contact sync
                        // happened to touch/update the same row.
                        $customer->update(['created_by' => 'Lead Connector Sync']);
                    } else {
                        $results['updated']++;
                    }

                    $results['pulled']++;
                } catch (\Exception $e) {
                    $results['errors']++;
                    $results['error_details'][] = [
                        'contact_id' => $contact['id'] ?? null,
                        'name' => $contact['firstName'] ?? 'Unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $page++;
            $total = $response['total'] ?? $response['meta']['total'] ?? count($contacts);

            usleep(100000);
        } while ($page * $limit < $total && ! empty($contacts));

        if ($fetchComplete) {
            try {
                $results['deactivated'] = $this->softDeleteMissingCustomers($tenantId, $seenGhlContactIds);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = ['error' => 'Customer delete-sync failed: '.$e->getMessage()];
                Log::error('GHL contact delete-sync failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Delete-sync: a Customer previously synced from GHL (ghl_contact_id
     * set) that no longer appears in GHL's current, *fully*-fetched contact
     * list is soft-deleted — never hard-deleted. Customer already uses
     * SoftDeletes, so this is fully reversible (bulkPullContacts()'s own
     * restore-then-updateOrCreate step above undoes it the moment the
     * contact reappears) and, critically, never breaks a
     * Booking/RentalTransaction/ProductTransaction foreign key that still
     * points at the row — a soft delete only hides it from default queries
     * (e.g. the staff Customers list), it never removes the row or any data
     * that references it.
     *
     * Deliberately distinct from the staff-initiated
     * CustomerService::hardDelete() flow (permanent, cancels any upcoming
     * GHL booking first, requires explicit staff confirmation) — this
     * automated sync path is intentionally much more conservative: it never
     * permanently erases anything and never touches GHL itself.
     *
     * Guarded on a non-empty $seenGhlContactIds — see
     * GhlProductSyncService::deactivateMissingCategories()'s doc comment
     * for why a successful-but-empty fetch must never be trusted as "GHL
     * has zero contacts now." Only ever runs when $fetchComplete (see
     * caller) — a contact list that failed partway through pagination must
     * never be treated as the complete current picture of who GHL still
     * has.
     */
    private function softDeleteMissingCustomers(string $tenantId, array $seenGhlContactIds): int
    {
        if (empty($seenGhlContactIds)) {
            return 0;
        }

        $stale = Customer::where('tenant_id', $tenantId)
            ->whereNotNull('ghl_contact_id')
            ->whereNotIn('ghl_contact_id', $seenGhlContactIds)
            ->get();

        foreach ($stale as $customer) {
            $customer->delete();
        }

        return $stale->count();
    }

    public function bulkSyncContacts(string $tenantId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $customers = Customer::where('tenant_id', $tenantId)->get();

        foreach ($customers as $customer) {
            try {
                $this->syncContactToGhl($customer);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'error' => $e->getMessage(),
                ];
            }

            usleep(100000);
        }

        return $results;
    }

    /** Delete the linked GHL contact. Non-blocking: failures are logged, never thrown, so the local delete always proceeds. */
    public function deleteContactFromGhl(Customer $customer): void
    {
        if (! $customer->ghl_contact_id) {
            return;
        }

        try {
            $this->client->delete("contacts/{$customer->ghl_contact_id}");
        } catch (\Exception $e) {
            Log::error('GHL contact delete failed', [
                'customer_id' => $customer->id,
                'ghl_contact_id' => $customer->ghl_contact_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createOpportunity(Booking $booking): ?string
    {
        $customer = $booking->customer;

        if (! $customer->ghl_contact_id) {
            $this->syncContactToGhl($customer);
            $customer = $customer->fresh();
        }

        if (! $customer->ghl_contact_id) {
            return null;
        }

        $payload = [
            'contactId' => $customer->ghl_contact_id,
            'name' => "Booking - {$booking->product->name} ({$booking->check_in_date} to {$booking->check_out_date})",
            'status' => 'new',
        ];

        try {
            $response = $this->client->post('opportunities/', $payload);
            $this->logOutbound('opportunity.created', $payload, $response);

            $ghlId = $response['opportunity']['id'] ?? null;
            if ($ghlId) {
                $booking->update(['ghl_opportunity_id' => $ghlId]);
            }

            return $ghlId;
        } catch (\Exception $e) {
            $this->logOutbound('opportunity.created', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function updateOpportunityStage(Booking $booking, string $stage): void
    {
        if (! $booking->ghl_opportunity_id) {
            return;
        }

        $payload = ['status' => $stage];

        try {
            $response = $this->client->put(
                "opportunities/{$booking->ghl_opportunity_id}",
                $payload
            );
            $this->logOutbound('opportunity.stage_changed', $payload, $response);
        } catch (\Exception $e) {
            $this->logOutbound('opportunity.stage_changed', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function logOutbound(string $eventType, array $requestPayload, array $responsePayload): void
    {
        WebhookLog::create([
            'source' => 'ghl',
            'event_type' => "outbound.{$eventType}",
            'payload' => [
                'request' => $requestPayload,
                'response' => $responsePayload,
            ],
            'status' => 'processed',
        ]);
    }

    public function handleInboundWebhook(array $payload, string $eventType): void
    {
        $log = WebhookLog::create([
            'source' => 'ghl',
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'received',
        ]);

        try {
            match ($eventType) {
                'contact.created' => $this->handleContactCreated($payload),
                'contact.updated' => $this->handleContactUpdated($payload),
                'opportunity.created' => $this->handleOpportunityCreated($payload),
                'opportunity.stage_changed' => $this->handleOpportunityStageChanged($payload),
                'InvoicePaid' => $this->handleInvoicePaid($payload),
                'InvoicePartiallyPaid' => $this->handleInvoicePartiallyPaid($payload),
                default => Log::info("Unhandled GHL event: {$eventType}"),
            };

            $log->update(['status' => 'processed']);
        } catch (\Exception $e) {
            $log->update(['status' => 'failed']);
            Log::error('GHL webhook processing failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleContactCreated(array $payload): void
    {
        $contact = $payload['contact'] ?? $payload;

        $customer = Customer::updateOrCreate(
            ['ghl_contact_id' => $contact['id']],
            [
                'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'ghl_sync_status' => 'synced',
                'ghl_last_synced_at' => now(),
                'tenant_id' => $this->resolveTenantId(),
            ]
        );

        // Only on genuine creation — see bulkPullContacts()'s identical guard.
        if ($customer->wasRecentlyCreated) {
            $customer->update(['created_by' => 'Lead Connector Sync']);
        }
    }

    private function handleContactUpdated(array $payload): void
    {
        $contact = $payload['contact'] ?? $payload;

        Customer::where('ghl_contact_id', $contact['id'])
            ->update([
                'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'ghl_sync_status' => 'synced',
                'ghl_last_synced_at' => now(),
            ]);
    }

    private function handleOpportunityCreated(array $payload): void
    {
        $opportunity = $payload['opportunity'] ?? $payload;
        $contactId = $opportunity['contactId'] ?? null;

        if ($contactId) {
            $customer = Customer::where('ghl_contact_id', $contactId)->first();
            if ($customer) {
                Booking::where('customer_id', $customer->id)
                    ->whereNull('ghl_opportunity_id')
                    ->latest()
                    ->first()
                    ?->update(['ghl_opportunity_id' => $opportunity['id']]);
            }
        }
    }

    private function handleOpportunityStageChanged(array $payload): void
    {
        $opportunity = $payload['opportunity'] ?? $payload;
        $status = $opportunity['status'] ?? null;

        if ($opportunity['id'] ?? null) {
            $stageMap = [
                'new' => 'pending',
                'booked' => 'confirmed',
                'lost' => 'cancelled',
            ];

            $booking = Booking::where('ghl_opportunity_id', $opportunity['id'])->first();
            if ($booking && $status && isset($stageMap[$status])) {
                $booking->update(['status' => $stageMap[$status]]);
            }
        }
    }

    /** Invoice paid in full via GHL (e.g. customer paid a GHL-hosted invoice link directly). */
    private function handleInvoicePaid(array $payload): void
    {
        $this->applyInvoiceStatus($payload, 'paid');
    }

    /** Partial payment recorded against a GHL invoice — not yet fully paid. */
    private function handleInvoicePartiallyPaid(array $payload): void
    {
        $this->applyInvoiceStatus($payload, 'partially_paid');
    }

    private function applyInvoiceStatus(array $payload, string $status): void
    {
        $ghlInvoiceId = $payload['_id'] ?? null;

        if (! $ghlInvoiceId) {
            return;
        }

        $booking = Booking::where('ghl_invoice_id', $ghlInvoiceId)->first();

        if ($booking) {
            $this->markInvoiceStatus($booking, $status);

            return;
        }

        // Booking-less invoice — a POS Product Sales "card" sale
        // (GhlBookingService::createProductSaleInvoice()). Only these ever
        // have their own ghl_invoice_id with no booking_id; a booking-linked
        // transaction's invoice always belongs to the booking instead.
        $productTransaction = ProductTransaction::where('ghl_invoice_id', $ghlInvoiceId)->whereNull('booking_id')->first();

        if ($productTransaction) {
            $this->markProductTransactionInvoiceStatus($productTransaction, $status);
        }
    }

    private function markInvoiceStatus(Booking $booking, string $status): void
    {
        $booking->update(['ghl_invoice_status' => $status]);

        if ($status === 'paid') {
            // Resolved lazily to avoid a circular constructor dependency
            // (GhlService -> RentalTransactionService -> GhlBookingService -> GhlService).
            $rentalTransactionService = app(RentalTransactionService::class);

            $booking->transactions()->whereNotIn('status', ['paid'])->get()->each(
                fn (RentalTransaction $rentalTransaction) => $rentalTransactionService->syncPaidStatusFromGhl($rentalTransaction)
            );

            // Confirm for both customer online (`requested`) and staff cash
            // (`pending`) once payment is paid — never leave Paid + unconfirmed.
            if (in_array($booking->status, ['requested', 'pending'], true)) {
                try {
                    // Resolved lazily to avoid a circular constructor dependency
                    // (BookingService -> GhlBookingService -> GhlService).
                    app(BookingService::class)->autoConfirmAfterPayment($booking);
                } catch (\Exception $e) {
                    Log::error('Auto-confirm after Text2Pay payment failed', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** ProductTransaction-typed sibling of markInvoiceStatus() — a booking-less "card" product sale has no booking/status to auto-confirm, just its own status/ghl_invoice_status to flip. */
    private function markProductTransactionInvoiceStatus(ProductTransaction $productTransaction, string $status): void
    {
        $productTransaction->update(['ghl_invoice_status' => $status]);

        if ($status === 'paid' && ! $productTransaction->isPaid()) {
            // Resolved lazily, same circular-dependency reason as above.
            app(ProductTransactionService::class)->syncPaidStatusFromGhl($productTransaction);
        }
    }

    /**
     * Live-checks GHL for invoice payment when our local `ghl_invoice_status`
     * hasn't caught up yet — self-heals the customer/staff invoice pages'
     * paid-status gating when the inbound InvoicePaid webhook never arrives
     * (e.g. no publicly reachable webhook URL configured for this
     * deployment, which is the common case in local dev). Cheap no-op once
     * already paid or when there's no invoice to check.
     */
    public function reconcileInvoiceStatus(Booking $booking): Booking
    {
        $booking->loadMissing('transactions');

        // Local payment already known (invoice status OR a paid transaction),
        // but booking still stuck requested/pending — autoConfirmAfterPayment()
        // must have failed or never finished (e.g. GHL briefly unreachable).
        // Retry here so the staff list / customer portal never show Paid +
        // requested forever. Also covers transactions marked paid without
        // ghl_invoice_status being updated yet.
        $locallyPaid = $booking->ghl_invoice_status === 'paid'
            || $booking->transactions->contains(fn (RentalTransaction $t) => $t->isPaid());

        if ($locallyPaid && in_array($booking->status, ['requested', 'pending'], true)) {
            try {
                return app(BookingService::class)->autoConfirmAfterPayment($booking);
            } catch (\Exception $e) {
                Log::error('Auto-confirm retry during invoice reconciliation failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $booking->ghl_invoice_id || $booking->ghl_invoice_status === 'paid') {
            return $booking;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return $booking;
        }

        try {
            $invoice = $this->client->get("invoices/{$booking->ghl_invoice_id}", [
                'altId' => $locationId,
                'altType' => 'location',
            ]);
        } catch (\Exception $e) {
            return $booking;
        }

        $status = $invoice['status'] ?? null;
        if ($status && $status !== $booking->ghl_invoice_status) {
            $this->markInvoiceStatus($booking, $status);
        }

        return $booking->fresh() ?? $booking;
    }

    /**
     * Batch counterpart to reconcileInvoiceStatus(), for list endpoints
     * (BookingController::index(), CustomerPortalController::bookings())
     * where reconciling N rows one at a time — N sequential blocking GHL
     * HTTP round-trips before the response can return — measurably adds
     * several seconds to a single page load once more than a handful of
     * rows are still unpaid (confirmed live: ~6.5s for 17 pending rows on
     * one page, vs. well under 1s once batched). Every row still goes
     * through exactly the same logic reconcileInvoiceStatus() would apply
     * to it on its own, in the same order, with the same side effects —
     * only the live "GET the invoice from GHL" calls themselves are fired
     * concurrently via GhlClient::poolGet() instead of one after another.
     * The rarer autoConfirmAfterPayment() retry branch (locally paid but
     * status stuck) is left sequential and per-row, same as before — it
     * has its own real GHL calendar-booking side effects per booking and
     * is uncommon enough that batching it isn't worth the added risk.
     *
     * @param  iterable<Booking>  $bookings
     * @return array<int, Booking> reconciled bookings, same order as input
     */
    public function reconcileInvoiceStatusBatch(iterable $bookings): array
    {
        $bookings = collect($bookings)->values();
        $results = [];
        $pending = [];

        foreach ($bookings as $i => $booking) {
            $booking->loadMissing('transactions');

            $locallyPaid = $booking->ghl_invoice_status === 'paid'
                || $booking->transactions->contains(fn (RentalTransaction $t) => $t->isPaid());

            if ($locallyPaid && in_array($booking->status, ['requested', 'pending'], true)) {
                try {
                    $results[$i] = app(BookingService::class)->autoConfirmAfterPayment($booking);
                } catch (\Exception $e) {
                    Log::error('Auto-confirm retry during invoice reconciliation failed', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                    $results[$i] = $booking;
                }

                continue;
            }

            if (! $booking->ghl_invoice_id || $booking->ghl_invoice_status === 'paid') {
                $results[$i] = $booking;

                continue;
            }

            $pending[$i] = $booking;
        }

        if (empty($pending)) {
            ksort($results);

            return array_values($results);
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            foreach ($pending as $i => $booking) {
                $results[$i] = $booking;
            }
            ksort($results);

            return array_values($results);
        }

        try {
            $requests = [];
            foreach ($pending as $i => $booking) {
                $requests[(string) $i] = [
                    'endpoint' => "invoices/{$booking->ghl_invoice_id}",
                    'query' => ['altId' => $locationId, 'altType' => 'location'],
                ];
            }

            $responses = $this->client->poolGet($requests);
        } catch (\Exception $e) {
            // Same fail-open behavior as the single-row version's catch —
            // leave these rows exactly as they were, self-heal again next poll.
            foreach ($pending as $i => $booking) {
                $results[$i] = $booking;
            }
            ksort($results);

            return array_values($results);
        }

        foreach ($pending as $i => $booking) {
            $response = $responses[(string) $i] ?? null;

            if ($response instanceof \Throwable || ! is_array($response)) {
                $results[$i] = $booking;

                continue;
            }

            $status = $response['status'] ?? null;
            if ($status && $status !== $booking->ghl_invoice_status) {
                $this->markInvoiceStatus($booking, $status);
                $results[$i] = $booking->fresh() ?? $booking;
            } else {
                $results[$i] = $booking;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * ProductTransaction-typed sibling of reconcileInvoiceStatus() — self-heals a
     * pending "card" POS product sale when the InvoicePaid webhook never
     * arrives, same rationale as the booking version. No autoConfirm
     * equivalent needed here (a ProductTransaction has no separate
     * status/calendar booking to advance — status/ghl_invoice_status are
     * the whole story). Cheap no-op once already paid or when there's no
     * invoice. Renamed from reconcileTransactionInvoiceStatus() as part of
     * the 2026-08-10 transactions refactor.
     */
    public function reconcileProductTransactionInvoiceStatus(ProductTransaction $productTransaction): ProductTransaction
    {
        if (! $productTransaction->ghl_invoice_id || $productTransaction->isPaid()) {
            return $productTransaction;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return $productTransaction;
        }

        try {
            $invoice = $this->client->get("invoices/{$productTransaction->ghl_invoice_id}", [
                'altId' => $locationId,
                'altType' => 'location',
            ]);
        } catch (\Exception $e) {
            return $productTransaction;
        }

        $status = $invoice['status'] ?? null;
        if ($status && $status !== $productTransaction->ghl_invoice_status) {
            $this->markProductTransactionInvoiceStatus($productTransaction, $status);
        }

        return $productTransaction->fresh();
    }

    private function resolveTenantId(): string
    {
        return EngageSetting::first()?->tenant_id ?? 'default';
    }
}
