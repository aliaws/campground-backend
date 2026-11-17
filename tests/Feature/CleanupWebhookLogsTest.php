<?php

namespace Tests\Feature;

use App\Models\EngageBooking;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class CleanupWebhookLogsTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private function log(string $event, array $payload, string $createdAt): WebhookLog
    {
        $log = WebhookLog::create(['source' => 'ghl', 'event_type' => $event, 'payload' => $payload, 'status' => 'processed']);
        DB::table('webhook_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);

        return $log;
    }

    public function test_links_known_records_and_deletes_duplicates_and_unlinked_webhooks(): void
    {
        $org = $this->makeOrganization('Camp');
        $customerId = $this->makeCustomer([$org]);
        $bookingId = $this->makeBooking($org, $customerId, $this->makeProduct($org));
        $invoiceId = 'inv-'.Str::random(10);
        EngageBooking::whereKey($bookingId)->update(['ghl_invoice_id' => $invoiceId]);

        $old = now()->subDays(10);
        $paid = ['type' => 'InvoicePaid', '_id' => $invoiceId, 'status' => 'paid'];
        $first = $this->log('InvoicePaid', $paid, $old->toDateTimeString());
        $copy = $this->log('InvoicePaid', $paid, $old->copy()->addSecond()->toDateTimeString());
        $unknown = $this->log('InvoicePaid', ['_id' => 'unknown-'.Str::random(6), 'status' => 'paid'], $old->toDateTimeString());
        $recentUnknown = $this->log('InvoicePaid', ['_id' => 'recent-'.Str::random(6), 'status' => 'paid'], now()->toDateTimeString());
        $outbound = $this->log('outbound.booking.processing-fees', ['request' => ['products' => []]], $old->toDateTimeString());
        $outboundKnown = $this->log('outbound.invoice.fetched', ['request' => ['invoiceId' => $invoiceId]], $old->toDateTimeString());

        $this->artisan('webhook-logs:cleanup', ['--force' => true])->assertSuccessful();

        $first->refresh();
        $this->assertSame('booking', $first->loggable_type);
        $this->assertSame($bookingId, $first->loggable_id);
        $this->assertNull(WebhookLog::find($copy->id));
        $this->assertNull(WebhookLog::find($unknown->id));
        $this->assertNotNull(WebhookLog::find($recentUnknown->id), 'recent unlinked webhooks are kept');
        $this->assertNotNull(WebhookLog::find($outbound->id), 'outbound logs are never deleted');
        $this->assertSame($bookingId, $outboundKnown->fresh()->loggable_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $log = $this->log('InvoicePaid', ['_id' => 'nothing-'.Str::random(6)], now()->subDays(10)->toDateTimeString());

        $this->artisan('webhook-logs:cleanup', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(WebhookLog::find($log->id));
    }

    public function test_booking_outranks_campsite_transaction_on_one_log(): void
    {
        $org = $this->makeOrganization('Camp');
        $bookingId = $this->makeBooking($org, $this->makeCustomer([$org]), $this->makeProduct($org));
        $booking = EngageBooking::findOrFail($bookingId);
        $log = $this->log('InvoicePaid', [], now()->toDateTimeString());

        $customer = $booking->customer;
        $log->linkTo($customer);
        $log->linkTo($booking);
        $log->linkTo($customer);

        $this->assertSame('booking', $log->fresh()->loggable_type);
    }
}
