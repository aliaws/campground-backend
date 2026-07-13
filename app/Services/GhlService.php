<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EngageSetting;
use App\Models\Transaction;
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

        return $results;
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

        Customer::updateOrCreate(
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

        if (! $booking) {
            return;
        }

        $this->markInvoiceStatus($booking, $status);
    }

    private function markInvoiceStatus(Booking $booking, string $status): void
    {
        $booking->update(['ghl_invoice_status' => $status]);

        if ($status === 'paid') {
            $booking->transactions()->whereNotIn('payment_status', ['paid'])->get()->each(
                fn (Transaction $transaction) => $transaction->update([
                    'payment_status' => 'paid',
                    'invoice_status' => 'completed',
                ])
            );

            if ($booking->status === 'requested') {
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

    /**
     * Live-checks GHL for invoice payment when our local `ghl_invoice_status`
     * hasn't caught up yet — self-heals the guest/staff invoice pages'
     * paid-status gating when the inbound InvoicePaid webhook never arrives
     * (e.g. no publicly reachable webhook URL configured for this
     * deployment, which is the common case in local dev). Cheap no-op once
     * already paid or when there's no invoice to check.
     */
    public function reconcileInvoiceStatus(Booking $booking): Booking
    {
        if (! $booking->ghl_invoice_id) {
            return $booking;
        }

        // The invoice is already known paid locally, but the booking is still
        // stuck 'requested' — autoConfirmAfterPayment() must have failed or
        // never ran to completion (e.g. GHL was briefly unreachable when the
        // webhook/first reconciliation fired). Retry it here too, not just the
        // first time we learn about payment, or a paid booking can be stuck
        // showing "requested" forever.
        if ($booking->ghl_invoice_status === 'paid') {
            if ($booking->status === 'requested') {
                try {
                    return app(BookingService::class)->autoConfirmAfterPayment($booking);
                } catch (\Exception $e) {
                    Log::error('Auto-confirm retry during invoice reconciliation failed', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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

        return $booking->fresh();
    }

    private function resolveTenantId(): string
    {
        return EngageSetting::first()?->tenant_id ?? 'default';
    }
}
