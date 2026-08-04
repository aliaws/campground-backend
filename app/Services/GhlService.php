<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class GhlService
{
    public function __construct(
        private GhlClient $client,
    ) {}

    public function syncContactToGhl(Customer $customer, ?string $orgLocationId = null): ?string
    {
        $orgLocationId ??= OrganizationLocationResolver::resolveDefaultLocationId();

        $customer->update(['ghl_sync_status' => 'pending']);

        $ghlLocationId = $this->client->getLocationId();

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
            $ghlContactId = $customer->ghlContactIdFor($orgLocationId);

            if ($ghlContactId) {
                // PUT does not accept locationId
                $response = $this->client->put("contacts/{$ghlContactId}", $sharedFields);

                $this->logOutbound('contact.updated', $sharedFields, $response);

                $customer->update([
                    'ghl_sync_status' => 'synced',
                    'ghl_last_synced_at' => now(),
                ]);

                return $ghlContactId;
            }

            // POST requires GHL's locationId to identify the sub-account
            $createPayload = array_merge(['locationId' => $ghlLocationId], $sharedFields);
            $response = $this->client->post('contacts/', $createPayload);

            $this->logOutbound('contact.created', $createPayload, $response);

            $ghlId = $response['contact']['id']
                ?? $response['id']
                ?? $response['_id']
                ?? $response['data']['id']
                ?? $response['data']['_id']
                ?? null;

            if ($ghlId) {
                $customer->setGhlContactIdFor($orgLocationId, $ghlId);
                $customer->update([
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

                    $customer->setGhlContactIdFor($orgLocationId, $existingId);
                    $customer->update([
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
    public function bulkPullContacts(string $locationId): array
    {
        $locationId = $this->client->getLocationId();

        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $results = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0, 'error_details' => []];
        $page = 0;
        $limit = 100;
        $total = null;

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
                break;
            }

            $contacts = $response['contacts'] ?? [];

            foreach ($contacts as $contact) {
                try {
                    $name = trim(
                        ($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')
                    ) ?: ($contact['name'] ?? 'Unknown');

                    $link = CustomerLocation::where('ghl_contact_id', $contact['id'])->first();
                    $customer = $link ? Customer::find($link->customer_id) : null;

                    if ($customer) {
                        $customer->update([
                            'name' => $name,
                            'email' => $contact['email'] ?? null,
                            'phone' => $contact['phone'] ?? null,
                            'ghl_sync_status' => 'synced',
                            'ghl_last_synced_at' => now(),
                        ]);
                        $customer->attachLocation($locationId, $contact['id']);
                        $results['updated']++;
                    } else {
                        $customer = Customer::create([
                            'name' => $name,
                            'email' => $contact['email'] ?? null,
                            'phone' => $contact['phone'] ?? null,
                            'ghl_sync_status' => 'synced',
                            'ghl_last_synced_at' => now(),
                        ]);
                        $customer->attachLocation($locationId, $contact['id']);
                        $results['created']++;
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

        return $results;
    }

    public function bulkSyncContacts(string $locationId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $customers = Customer::whereHas(
            'locationLinks',
            fn ($q) => $q->where('engage_organization_location_id', $locationId)
        )->get();

        foreach ($customers as $customer) {
            try {
                $this->syncContactToGhl($customer, $locationId);
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
    public function deleteContactFromGhl(Customer $customer, ?string $locationId = null): void
    {
        $ghlContactId = $customer->ghlContactIdFor($locationId);

        if (! $ghlContactId) {
            return;
        }

        try {
            $this->client->delete("contacts/{$ghlContactId}");
        } catch (\Exception $e) {
            Log::error('GHL contact delete failed', [
                'customer_id' => $customer->id,
                'ghl_contact_id' => $ghlContactId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createOpportunity(Booking $booking): ?string
    {
        $customer = $booking->customer;

        $locationId = $booking->engage_organization_location_id;
        $ghlContactId = $customer->ghlContactIdFor($locationId);

        if (! $ghlContactId) {
            $this->syncContactToGhl($customer, $locationId);
            $ghlContactId = $customer->fresh()->ghlContactIdFor($locationId);
        }

        if (! $ghlContactId) {
            return null;
        }

        $payload = [
            'contactId' => $ghlContactId,
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
        $locationId = OrganizationLocationResolver::resolveDefaultLocationId();

        $link = CustomerLocation::where('ghl_contact_id', $contact['id'])->first();
        $fields = [
            'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'ghl_sync_status' => 'synced',
            'ghl_last_synced_at' => now(),
        ];

        if ($link) {
            Customer::where('id', $link->customer_id)->update($fields);

            return;
        }

        $customer = Customer::create($fields);
        $customer->attachLocation($locationId, $contact['id']);
    }

    private function handleContactUpdated(array $payload): void
    {
        $contact = $payload['contact'] ?? $payload;

        $link = CustomerLocation::where('ghl_contact_id', $contact['id'])->first();

        if (! $link) {
            return;
        }

        Customer::where('id', $link->customer_id)->update([
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
            $link = CustomerLocation::where('ghl_contact_id', $contactId)->first();
            $customer = $link ? Customer::find($link->customer_id) : null;
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

        // Invoice metadata lives only on transactions now (polymorphic to
        // Booking or Order). Match by ghl_invoice_id first.
        $transaction = Transaction::where('ghl_invoice_id', $ghlInvoiceId)->first();

        if (! $transaction) {
            return;
        }

        if ($transaction->transactionable_type === Booking::class) {
            $booking = $transaction->transactionable;
            if ($booking instanceof Booking) {
                $this->markInvoiceStatus($booking, $status);
            }

            return;
        }

        // Order / booking-less POS product sale — flip payment on the row itself.
        $this->markTransactionInvoiceStatus($transaction, $status);
    }

    private function markInvoiceStatus(Booking $booking, string $status): void
    {
        $tx = $booking->primaryTransaction();
        if ($tx) {
            $tx->update(['ghl_invoice_status' => $status]);
        }

        if ($status === 'paid') {
            $booking->transactions()->whereNotIn('payment_status', ['paid'])->get()->each(
                fn (Transaction $transaction) => $transaction->update([
                    'payment_status' => 'paid',
                    'invoice_status' => 'completed',
                ])
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

    /** Transaction-typed sibling of markInvoiceStatus() — a booking-less "card" product sale has no booking/status to auto-confirm, just its own payment_status/invoice_status to flip. */
    private function markTransactionInvoiceStatus(Transaction $transaction, string $status): void
    {
        $transaction->update(['ghl_invoice_status' => $status]);

        if ($status === 'paid' && $transaction->payment_status !== 'paid') {
            $transaction->update([
                'payment_status' => 'paid',
                'invoice_status' => 'completed',
            ]);
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
        $tx = $booking->primaryTransaction();

        // Local payment already known (invoice status OR a paid transaction),
        // but booking still stuck requested/pending — autoConfirmAfterPayment()
        // must have failed or never finished (e.g. GHL briefly unreachable).
        // Retry here so the staff list / customer portal never show Paid +
        // requested forever. Also covers transactions marked paid without
        // ghl_invoice_status being updated yet.
        $locallyPaid = $tx?->ghl_invoice_status === 'paid'
            || $booking->transactions->contains(fn (Transaction $t) => $t->payment_status === 'paid');

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

        if (! $tx?->ghl_invoice_id || $tx->ghl_invoice_status === 'paid') {
            return $booking;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return $booking;
        }

        try {
            $invoice = $this->client->get("invoices/{$tx->ghl_invoice_id}", [
                'altId' => $locationId,
                'altType' => 'location',
            ]);
        } catch (\Exception $e) {
            return $booking;
        }

        $status = $invoice['status'] ?? null;
        if ($status && $status !== $tx->ghl_invoice_status) {
            $this->markInvoiceStatus($booking, $status);
        }

        return $booking->fresh(['transactions']) ?? $booking;
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
            $tx = $booking->primaryTransaction();

            $locallyPaid = $tx?->ghl_invoice_status === 'paid'
                || $booking->transactions->contains(fn (Transaction $t) => $t->payment_status === 'paid');

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

            if (! $tx?->ghl_invoice_id || $tx->ghl_invoice_status === 'paid') {
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
                $invoiceId = $booking->primaryTransaction()?->ghl_invoice_id;
                if (! $invoiceId) {
                    $results[$i] = $booking;

                    continue;
                }
                $requests[(string) $i] = [
                    'endpoint' => "invoices/{$invoiceId}",
                    'query' => ['altId' => $locationId, 'altType' => 'location'],
                ];
            }

            $responses = empty($requests) ? [] : $this->client->poolGet($requests);
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
            if (! isset($requests[(string) $i])) {
                $results[$i] ??= $booking;

                continue;
            }

            $response = $responses[(string) $i] ?? null;

            if ($response instanceof \Throwable || ! is_array($response)) {
                $results[$i] = $booking;

                continue;
            }

            $tx = $booking->primaryTransaction();
            $status = $response['status'] ?? null;
            if ($status && $tx && $status !== $tx->ghl_invoice_status) {
                $this->markInvoiceStatus($booking, $status);
                $results[$i] = $booking->fresh(['transactions']) ?? $booking;
            } else {
                $results[$i] = $booking;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Transaction-typed sibling of reconcileInvoiceStatus() — self-heals a
     * pending "card" POS product sale when the InvoicePaid webhook never
     * arrives, same rationale as the booking version. No autoConfirm
     * equivalent needed here (a Transaction has no separate status/calendar
     * booking to advance — payment_status/invoice_status are the whole
     * story). Cheap no-op once already paid or when there's no invoice.
     */
    public function reconcileTransactionInvoiceStatus(Transaction $transaction): Transaction
    {
        if (! $transaction->ghl_invoice_id || $transaction->payment_status === 'paid') {
            return $transaction;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return $transaction;
        }

        try {
            $invoice = $this->client->get("invoices/{$transaction->ghl_invoice_id}", [
                'altId' => $locationId,
                'altType' => 'location',
            ]);
        } catch (\Exception $e) {
            return $transaction;
        }

        $status = $invoice['status'] ?? null;
        if ($status && $status !== $transaction->ghl_invoice_status) {
            $this->markTransactionInvoiceStatus($transaction, $status);
        }

        return $transaction->fresh();
    }
}
