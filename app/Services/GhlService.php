<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageCustomerLocation;
use App\Models\EngageProductTransaction;
use App\Models\EngageRentalTransaction;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class GhlService
{
    public function __construct(
        private GhlClient $client,
    ) {}

    public function syncContactToGhl(EngageCustomer $customer, ?string $orgLocationId = null): ?string
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

    public function updateContactInGhl(EngageCustomer $customer): void
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
        // GHL's own location id (needed for the API call itself) is a
        // different value from our internal $locationId
        // (engage_organization_location_id) — kept as a separate variable
        // so the rest of this method's location-scoping (CustomerLocation
        // lookups, archiveCustomer(), etc.) never accidentally uses GHL's
        // id in place of ours.
        $ghlLocationId = $this->client->getLocationId();

        if (! $ghlLocationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $results = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'restored' => 0, 'errors' => 0, 'deactivated' => 0, 'error_details' => []];
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
                    'locationId' => $ghlLocationId,
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
                    $email = $contact['email'] ?? null;

                    // A customer archived by a prior delete-sync run (see
                    // softDeleteMissingCustomers()/CustomerService::
                    // archiveCustomer() below) whose contact has reappeared
                    // in GHL needs restoring first. Matched by EMAIL, not
                    // ghl_contact_id — a contact removed and re-added in GHL
                    // is assigned a brand-new id, so an id-based match would
                    // never find the archived row and would silently create
                    // a genuine duplicate Customer instead. Only checked
                    // when there's no already-active link for this contact
                    // id at this location (the common case), to avoid an
                    // extra query per row on every sync. restoreFromArchive()
                    // re-attaches the customer's CustomerLocation row for
                    // this location with this ghl_contact_id, so the lookup
                    // right below will find it.
                    $hasActiveMatch = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
                        ->where('ghl_contact_id', $contact['id'])
                        ->exists();
                    $restored = false;

                    if (! $hasActiveMatch && $email) {
                        $archive = app(CustomerService::class)->findArchiveByEmail($locationId, $email);

                        if ($archive) {
                            app(CustomerService::class)->restoreFromArchive($archive, $contact['id']);
                            $restored = true;
                        } else {
                            // Not archived, but an active Customer at this
                            // location may already exist under this email
                            // with no ghl_contact_id link yet (or a stale
                            // one) — e.g. a customer created locally before
                            // ever syncing, or a link that was lost outside
                            // the normal archive/restore path. customers.email
                            // is globally unique, so falling through to
                            // EngageCustomer::create() below would otherwise throw
                            // a duplicate-key error instead of silently
                            // creating a duplicate — re-linking here is what
                            // makes the sync self-healing rather than just
                            // failing loudly on every subsequent run.
                            $existingLink = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
                                ->whereHas('customer', function ($q) use ($email) {
                                    $q->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                                })
                                ->first();

                            if ($existingLink) {
                                $existingLink->update(['ghl_contact_id' => $contact['id']]);
                                $restored = true;
                            } else {
                                // No location link at all yet, but a Customer
                                // with this email may still exist globally
                                // (e.g. created for a different location, or
                                // a pre-location-model legacy row) — attach
                                // this location to it instead of colliding
                                // with the global unique(email) constraint.
                                $existingCustomer = EngageCustomer::withTrashed()
                                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                                    ->first();

                                if ($existingCustomer) {
                                    if ($existingCustomer->trashed()) {
                                        $existingCustomer->restore();
                                    }
                                    $existingCustomer->attachLocation($locationId, $contact['id']);
                                    $restored = true;
                                }
                            }
                        }
                    }

                    $link = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
                        ->where('ghl_contact_id', $contact['id'])
                        ->first();
                    $customer = $link ? EngageCustomer::find($link->customer_id) : null;

                    if ($customer) {
                        $customer->update([
                            'name' => $name,
                            'email' => $email,
                            'phone' => $contact['phone'] ?? null,
                            'ghl_sync_status' => 'synced',
                            'ghl_last_synced_at' => now(),
                        ]);

                        if ($restored) {
                            $results['restored']++;
                        } else {
                            $results['updated']++;
                        }
                    } else {
                        $customer = EngageCustomer::create([
                            'name' => $name,
                            'email' => $email,
                            'phone' => $contact['phone'] ?? null,
                            'ghl_sync_status' => 'synced',
                            'ghl_last_synced_at' => now(),
                            'created_by' => 'Lead Connector Sync',
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

        if ($fetchComplete) {
            try {
                $results['deactivated'] = $this->softDeleteMissingCustomers($locationId, $seenGhlContactIds);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_details'][] = ['error' => 'Customer delete-sync failed: '.$e->getMessage()];
                Log::error('GHL contact delete-sync failed', ['engage_organization_location_id' => $locationId, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Delete-sync: a customer previously synced from GHL at this location
     * (a CustomerLocation row with ghl_contact_id set) that no longer
     * appears in GHL's current, *fully*-fetched contact list for this
     * location is archived via CustomerService::archiveCustomer() — their
     * location link is detached (and the Customer row itself soft-deleted
     * only if that was their last remaining location), never hard-deleted.
     * Fully reversible (bulkPullContacts()'s own email-match restore step
     * above undoes it the moment a same-email contact reappears) and,
     * critically, never breaks a Booking/RentalTransaction/ProductTransaction
     * foreign key that still points at the Customer row. See
     * archiveCustomer()'s own doc comment for the full reasoning.
     *
     * Deliberately distinct from the staff-initiated
     * CustomerService::hardDelete() flow (permanent, cancels any upcoming
     * GHL booking first, requires explicit staff confirmation, removes the
     * customer everywhere) — this automated sync path only ever affects the
     * one location being synced, never permanently erases anything, and
     * never touches GHL itself.
     *
     * Guarded on a non-empty $seenGhlContactIds — see
     * GhlProductSyncService::deactivateMissingCategories()'s doc comment
     * for why a successful-but-empty fetch must never be trusted as "GHL
     * has zero contacts now." Only ever runs when $fetchComplete (see
     * caller) — a contact list that failed partway through pagination must
     * never be treated as the complete current picture of who GHL still
     * has.
     */
    private function softDeleteMissingCustomers(string $locationId, array $seenGhlContactIds): int
    {
        if (empty($seenGhlContactIds)) {
            return 0;
        }

        $staleLinks = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
            ->whereNotNull('ghl_contact_id')
            ->whereNotIn('ghl_contact_id', $seenGhlContactIds)
            ->get();

        $customerService = app(CustomerService::class);

        foreach ($staleLinks as $link) {
            $customer = EngageCustomer::find($link->customer_id);
            if ($customer) {
                $customerService->archiveCustomer($customer, $locationId);
            }
        }

        return $staleLinks->count();
    }

    public function bulkSyncContacts(string $locationId): array
    {
        $results = ['synced' => 0, 'errors' => 0, 'error_details' => []];

        $customers = EngageCustomer::whereHas(
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
    public function deleteContactFromGhl(EngageCustomer $customer, ?string $locationId = null): void
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

    public function createOpportunity(EngageBooking $booking): ?string
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

    public function updateOpportunityStage(EngageBooking $booking, string $stage): void
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
        $locationId = OrganizationLocationResolver::resolveFromGhlLocationId($this->webhookGhlLocationId($payload, $contact));
        $email = $contact['email'] ?? null;

        // Mirrors bulkPullContacts()'s email-first archive restore — a
        // webhook-driven "contact created" for a previously-archived
        // customer's email must restore the original row, not create a
        // duplicate, exactly like the bulk pull. See
        // CustomerService::findArchiveByEmail()'s doc comment for why this
        // is matched by email rather than ghl_contact_id.
        $hasActiveMatch = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
            ->where('ghl_contact_id', $contact['id'])
            ->exists();

        if (! $hasActiveMatch && $email) {
            $archive = app(CustomerService::class)->findArchiveByEmail($locationId, $email);

            if ($archive) {
                app(CustomerService::class)->restoreFromArchive($archive, $contact['id']);
            }
        }

        $link = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
            ->where('ghl_contact_id', $contact['id'])
            ->first();
        $fields = [
            'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
            'email' => $email,
            'phone' => $contact['phone'] ?? null,
            'ghl_sync_status' => 'synced',
            'ghl_last_synced_at' => now(),
        ];

        if ($link) {
            EngageCustomer::where('id', $link->customer_id)->update($fields);

            return;
        }

        // Only on genuine creation — see bulkPullContacts()'s identical guard.
        $customer = EngageCustomer::create($fields + ['created_by' => 'Lead Connector Sync']);
        $customer->attachLocation($locationId, $contact['id']);
    }

    private function handleContactUpdated(array $payload): void
    {
        $contact = $payload['contact'] ?? $payload;
        $locationId = OrganizationLocationResolver::resolveFromGhlLocationId($this->webhookGhlLocationId($payload, $contact));
        $email = $contact['email'] ?? null;

        // Same email-first archive restore as handleContactCreated() above
        // — GHL can fire contact.updated rather than contact.created for a
        // contact that was recreated after being deleted, so this needs the
        // identical restore check, not just handleContactCreated().
        $hasActiveMatch = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
            ->where('ghl_contact_id', $contact['id'])
            ->exists();

        if (! $hasActiveMatch && $email) {
            $archive = app(CustomerService::class)->findArchiveByEmail($locationId, $email);

            if ($archive) {
                app(CustomerService::class)->restoreFromArchive($archive, $contact['id']);
            }
        }

        $link = EngageCustomerLocation::where('engage_organization_location_id', $locationId)
            ->where('ghl_contact_id', $contact['id'])
            ->first();

        if (! $link) {
            return;
        }

        EngageCustomer::where('id', $link->customer_id)->update([
            'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
            'email' => $email,
            'phone' => $contact['phone'] ?? null,
            'ghl_sync_status' => 'synced',
            'ghl_last_synced_at' => now(),
        ]);
    }

    /**
     * GHL's own sub-account id for this webhook, tried at every shape this
     * codebase has already seen a GHL payload use elsewhere (top-level, or
     * nested under the event's own object) — defensive, since GHL's exact
     * webhook shape isn't guaranteed identical across event types and this
     * has never been confirmed against a real multi-location payload.
     */
    private function webhookGhlLocationId(array $payload, array $nested): ?string
    {
        $id = $payload['locationId'] ?? $nested['locationId'] ?? $payload['location_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function handleOpportunityCreated(array $payload): void
    {
        $opportunity = $payload['opportunity'] ?? $payload;
        $contactId = $opportunity['contactId'] ?? null;

        if ($contactId) {
            $link = EngageCustomerLocation::where('ghl_contact_id', $contactId)->first();
            $customer = $link ? EngageCustomer::find($link->customer_id) : null;
            if ($customer) {
                EngageBooking::where('customer_id', $customer->id)
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

            $booking = EngageBooking::where('ghl_opportunity_id', $opportunity['id'])->first();
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

        $booking = EngageBooking::where('ghl_invoice_id', $ghlInvoiceId)->first();

        if ($booking) {
            $this->markInvoiceStatus($booking, $status);

            return;
        }

        // Booking-less invoice — a POS Product Sales "card" sale
        // (GhlBookingService::createProductSaleInvoice()). Only these ever
        // have their own ghl_invoice_id with no booking_id; a booking-linked
        // transaction's invoice always belongs to the booking instead.
        $productTransaction = EngageProductTransaction::where('ghl_invoice_id', $ghlInvoiceId)->whereNull('booking_id')->first();

        if ($productTransaction) {
            $this->markProductTransactionInvoiceStatus($productTransaction, $status);
        }
    }

    private function markInvoiceStatus(EngageBooking $booking, string $status): void
    {
        $booking->update(['ghl_invoice_status' => $status]);

        if ($status === 'paid') {
            // Resolved lazily to avoid a circular constructor dependency
            // (GhlService -> RentalTransactionService -> GhlBookingService -> GhlService).
            $rentalTransactionService = app(RentalTransactionService::class);

            $booking->transactions()->whereNotIn('status', ['paid'])->get()->each(
                fn (EngageRentalTransaction $rentalTransaction) => $rentalTransactionService->syncPaidStatusFromGhl($rentalTransaction)
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
    private function markProductTransactionInvoiceStatus(EngageProductTransaction $productTransaction, string $status): void
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
    public function reconcileInvoiceStatus(EngageBooking $booking): EngageBooking
    {
        $booking->loadMissing('transactions');

        // Local payment already known (invoice status OR a paid transaction),
        // but booking still stuck requested/pending — autoConfirmAfterPayment()
        // must have failed or never finished (e.g. GHL briefly unreachable).
        // Retry here so the staff list / customer portal never show Paid +
        // requested forever. Also covers transactions marked paid without
        // ghl_invoice_status being updated yet.
        $locallyPaid = $booking->ghl_invoice_status === 'paid'
            || $booking->transactions->contains(fn (EngageRentalTransaction $t) => $t->isPaid());

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
     * @param  iterable<EngageBooking>  $bookings
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
                || $booking->transactions->contains(fn (EngageRentalTransaction $t) => $t->isPaid());

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
    public function reconcileProductTransactionInvoiceStatus(EngageProductTransaction $productTransaction): EngageProductTransaction
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
}
