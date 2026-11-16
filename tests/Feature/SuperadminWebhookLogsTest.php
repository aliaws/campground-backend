<?php

namespace Tests\Feature;

use App\Models\WebhookLog;
use App\Services\GhlLocationContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class SuperadminWebhookLogsTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    public function test_new_rows_take_the_organization_from_the_payload_location_id(): void
    {
        $org = $this->makeOrganization('Camp');

        $inbound = WebhookLog::create(['source' => 'ghl', 'event_type' => 'InvoicePaid', 'status' => 'received', 'payload' => ['_id' => 'inv-1', 'altId' => $org->engage_location_id]]);
        $outbound = WebhookLog::create(['source' => 'ghl', 'event_type' => 'outbound.invoice.sent', 'status' => 'processed', 'payload' => ['request' => ['altId' => $org->engage_location_id], 'response' => []]]);

        $this->assertSame($org->id, $inbound->fresh()->engage_organization_location_id);
        $this->assertSame($org->id, $outbound->fresh()->engage_organization_location_id);
    }

    public function test_rows_without_a_location_id_use_the_current_organization_or_stay_empty(): void
    {
        $org = $this->makeOrganization('Camp');
        $empty = WebhookLog::create(['source' => 'ghl', 'event_type' => 'outbound.invoice.fetched', 'status' => 'processed', 'payload' => ['request' => []]]);
        $this->assertNull($empty->fresh()->engage_organization_location_id);

        app(GhlLocationContext::class)->set($org->id);
        $fromContext = WebhookLog::create(['source' => 'ghl', 'event_type' => 'outbound.invoice.fetched', 'status' => 'processed', 'payload' => ['request' => []]]);
        app(GhlLocationContext::class)->set(null);

        $this->assertSame($org->id, $fromContext->fresh()->engage_organization_location_id);
    }

    public function test_superadmin_lists_filters_sorts_and_reads_logs(): void
    {
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Beta');
        $marker = 'mark-'.Str::lower(Str::random(8));
        WebhookLog::create(['source' => 'ghl', 'event_type' => 'InvoicePaid', 'status' => 'processed', 'payload' => ['locationId' => $a->engage_location_id, 'invoiceNumber' => '1001', 'note' => $marker]]);
        WebhookLog::create(['source' => 'ghl', 'event_type' => 'outbound.booking.created', 'status' => 'failed', 'payload' => ['request' => ['locationId' => $b->engage_location_id], 'note' => $marker]]);
        $headers = $this->superadminHeaders();

        $all = $this->getJson('/api/v1/superadmin/webhook-logs?search='.$marker, $headers)->assertOk()->json('data');
        $this->assertSame(2, $all['total']);

        $onlyA = $this->getJson("/api/v1/superadmin/webhook-logs?search={$marker}&organization_ids={$a->id}", $headers)->json('data.data');
        $this->assertCount(1, $onlyA);
        $this->assertSame('Alpha', explode(' ', $onlyA[0]['organization']['name'])[0]);
        $this->assertSame('inbound', $onlyA[0]['direction']);
        $this->assertSame($a->engage_location_id, $onlyA[0]['ghl_location_id']);
        $this->assertStringContainsString('Invoice #1001', (string) $onlyA[0]['summary']);

        $outbound = $this->getJson("/api/v1/superadmin/webhook-logs?search={$marker}&direction=outbound&statuses=failed", $headers)->json('data.data');
        $this->assertCount(1, $outbound);
        $this->assertSame('outbound.booking.created', $outbound[0]['event_type']);

        $sorted = $this->getJson("/api/v1/superadmin/webhook-logs?search={$marker}&sort_by=event_type&sort_dir=asc", $headers)->json('data.data');
        $this->assertSame(['InvoicePaid', 'outbound.booking.created'], array_column($sorted, 'event_type'));

        $one = $this->getJson('/api/v1/superadmin/webhook-logs/'.$onlyA[0]['id'], $headers)->assertOk()->json('data');
        $this->assertSame($marker, $one['payload']['note']);

        $filters = $this->getJson('/api/v1/superadmin/webhook-logs/filters', $headers)->assertOk()->json('data');
        $this->assertContains('InvoicePaid', array_column($filters['event_types'], 'value'));
        $this->assertContains($a->id, array_column($filters['organizations'], 'value'));
    }

    public function test_an_inbound_webhook_is_stored_once_with_its_organization(): void
    {
        $org = $this->makeOrganization('Camp');
        $marker = 'evt-'.Str::lower(Str::random(8));

        $this->postJson('/api/v1/webhooks/ghl', ['type' => 'SomeUnhandledEvent', 'locationId' => $org->engage_location_id, 'marker' => $marker])->assertOk();

        $rows = WebhookLog::where('event_type', 'SomeUnhandledEvent')->get()->filter(fn ($l) => ($l->payload['marker'] ?? null) === $marker);
        $this->assertCount(1, $rows);
        $this->assertSame($org->id, $rows->first()->engage_organization_location_id);
        $this->assertSame('processed', $rows->first()->status);
    }

    public function test_only_superadmin_can_read_logs(): void
    {
        $org = $this->makeOrganization('Camp');
        $owner = $this->makeUser(['owner'], $org);

        $this->getJson('/api/v1/superadmin/webhook-logs', $this->tokenFor($owner, $org))->assertForbidden();
        $this->getJson('/api/v1/superadmin/webhook-logs')->assertUnauthorized();
    }
}
