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
        $payload = [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
        ];

        try {
            $response = $this->client->post('contacts/', $payload);

            $this->logOutbound('contact.created', $payload, $response);

            $ghlId = $response['contact']['id'] ?? null;
            if ($ghlId) {
                $customer->update(['ghl_contact_id' => $ghlId]);
            }

            return $ghlId;
        } catch (\Exception $e) {
            $this->logOutbound('contact.created', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function updateContactInGhl(Customer $customer): void
    {
        if (!$customer->ghl_contact_id) {
            return;
        }

        $payload = [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
        ];

        try {
            $response = $this->client->put("contacts/{$customer->ghl_contact_id}", $payload);
            $this->logOutbound('contact.updated', $payload, $response);
        } catch (\Exception $e) {
            $this->logOutbound('contact.updated', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
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
        WebhookLog::create([
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

            WebhookLog::where('id', $log->id ?? null)->update(['status' => 'processed']);
        } catch (\Exception $e) {
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
                'name' => $contact['name'] ?? 'Unknown',
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'tenant_id' => $this->resolveTenantId(),
            ]
        );
    }

    private function handleContactUpdated(array $payload): void
    {
        $contact = $payload['contact'] ?? $payload;

        Customer::where('ghl_contact_id', $contact['id'])
            ->update([
                'name' => $contact['name'] ?? 'Unknown',
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
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
