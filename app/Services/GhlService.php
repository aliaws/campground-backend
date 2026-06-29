<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Customer;
use App\Models\Reservation;
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

        $payload = [
            'locationId' => $locationId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];

        if ($customer->address && is_array($customer->address)) {
            $addr = $customer->address;
            if (!empty($addr['line1'])) $payload['address1'] = $addr['line1'];
            if (!empty($addr['city'])) $payload['city'] = $addr['city'];
            if (!empty($addr['state'])) $payload['state'] = $addr['state'];
            if (!empty($addr['postal_code'])) $payload['postalCode'] = $addr['postal_code'];
            if (!empty($addr['country'])) $payload['country'] = $addr['country'];
        }

        try {
            if ($customer->ghl_contact_id) {
                $response = $this->client->put("contacts/{$customer->ghl_contact_id}", $payload);

                $this->logOutbound('contact.updated', $payload, $response);

                $customer->update([
                    'ghl_sync_status' => 'synced',
                    'ghl_last_synced_at' => now(),
                ]);

                return $customer->ghl_contact_id;
            }

            $response = $this->client->post('contacts/', $payload);

            $this->logOutbound('contact.created', $payload, $response);

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
            Log::error('GHL contact sync failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            $customer->update(['ghl_sync_status' => 'error']);

            $this->logOutbound('contact.sync_failed', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function updateContactInGhl(Customer $customer): void
    {
        $this->syncContactToGhl($customer);
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

    public function createOpportunity(Reservation $reservation): ?string
    {
        $customer = $reservation->customer;

        if (!$customer->ghl_contact_id) {
            $this->syncContactToGhl($customer);
            $customer = $customer->fresh();
        }

        if (!$customer->ghl_contact_id) {
            return null;
        }

        $payload = [
            'contactId' => $customer->ghl_contact_id,
            'name' => "Reservation - {$reservation->product->name} ({$reservation->check_in_date} to {$reservation->check_out_date})",
            'status' => 'new',
        ];

        try {
            $response = $this->client->post('opportunities/', $payload);
            $this->logOutbound('opportunity.created', $payload, $response);

            $ghlId = $response['opportunity']['id'] ?? null;
            if ($ghlId) {
                $reservation->update(['ghl_opportunity_id' => $ghlId]);
            }

            return $ghlId;
        } catch (\Exception $e) {
            $this->logOutbound('opportunity.created', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function updateOpportunityStage(Reservation $reservation, string $stage): void
    {
        if (!$reservation->ghl_opportunity_id) {
            return;
        }

        $payload = ['status' => $stage];

        try {
            $response = $this->client->put(
                "opportunities/{$reservation->ghl_opportunity_id}",
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
                'name' => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
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
                'name' => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')) ?: ($contact['name'] ?? 'Unknown'),
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
                Reservation::where('customer_id', $customer->id)
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

            $reservation = Reservation::where('ghl_opportunity_id', $opportunity['id'])->first();
            if ($reservation && $status && isset($stageMap[$status])) {
                $reservation->update(['status' => $stageMap[$status]]);
            }
        }
    }

    private function resolveTenantId(): string
    {
        return \App\Models\EngageSetting::first()?->tenant_id ?? 'default';
    }
}
