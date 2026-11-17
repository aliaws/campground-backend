<?php

namespace App\Console\Commands;

use App\Models\EngageBooking;
use App\Models\EngageCustomerLocation;
use App\Models\EngageProductRental;
use App\Models\EngageProductTransaction;
use App\Models\EngageRentalTransaction;
use App\Models\WebhookLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off clean-up of webhook_logs:
 *   1. links existing rows to the record they concern (loggable_type/id),
 *   2. deletes exact duplicates of inbound webhooks (until 2026-11-17 every
 *      inbound webhook was saved twice),
 *   3. deletes inbound webhooks still linked to nothing (an invoice/appointment/
 *      contact this app has no record of).
 * Our own outbound call logs are linked where possible but never deleted.
 */
class CleanupWebhookLogs extends Command
{
    protected $signature = 'webhook-logs:cleanup
        {--dry-run : Only report what would be linked and deleted}
        {--force : Skip the confirmation prompt}
        {--keep-days=2 : Never delete unlinked webhooks received in the last N days (their record may not be synced yet)}';

    protected $description = 'Link webhook logs to their records and delete duplicate or unlinked inbound webhooks';

    /** Copies of one webhook logged within this many seconds are duplicates. */
    private const DUPLICATE_WINDOW_SECONDS = 30;

    /** Payload keys whose values can identify a record. */
    private const ID_KEYS = ['_id', 'id', 'invoiceid', 'bookingid', 'servicebookingid', 'appointmentid', 'contactid'];

    /** @var array<string, array<string, string>> lookup name => [ghl id => "type:id"] */
    private array $maps = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepDays = max(0, (int) $this->option('keep-days'));

        $this->buildMaps();

        $links = $this->findLinks();
        $duplicates = $this->findDuplicates($links);
        $unlinked = $this->findUnlinkedInbound($links, $duplicates, $keepDays);

        $total = WebhookLog::count();
        $this->table(['', 'Rows'], [
            ['Webhook log rows now', $total],
            ['Rows to link to a record', count($links)],
            ['Duplicate inbound rows to delete', count($duplicates)],
            ["Unlinked inbound rows to delete (older than {$keepDays} days)", count($unlinked)],
            ['Rows left after clean-up', $total - count($duplicates) - count($unlinked)],
        ]);

        $byType = array_count_values(array_map(fn ($link) => explode(':', $link, 2)[0], $links));
        if ($byType) {
            ksort($byType);
            $this->table(['Linked to', 'Rows'], collect($byType)->map(fn ($c, $t) => [$t, $c])->values()->all());
        }

        if ($dryRun) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Link these rows and permanently delete the rows listed above?')) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($links, $duplicates, $unlinked) {
            foreach ($links as $logId => $link) {
                [$type, $id] = explode(':', $link, 2);
                DB::table('webhook_logs')->where('id', $logId)->update(['loggable_type' => $type, 'loggable_id' => $id]);
            }

            foreach (array_chunk(array_merge($duplicates, $unlinked), 500) as $chunk) {
                DB::table('webhook_logs')->whereIn('id', $chunk)->delete();
            }
        });

        Log::warning('webhook-logs:cleanup ran', [
            'linked' => count($links),
            'deleted_duplicates' => count($duplicates),
            'deleted_unlinked' => count($unlinked),
        ]);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /** Lead Connector id => local record, per lookup. */
    private function buildMaps(): void
    {
        $put = function (string $map, $ghlId, string $type, $id) {
            if (is_string($ghlId) && $ghlId !== '' && ! isset($this->maps[$map][$ghlId])) {
                $this->maps[$map][$ghlId] = $type.':'.$id;
            }
        };

        foreach (EngageBooking::query()->get(['id', 'ghl_invoice_id', 'ghl_booking_id', 'ghl_appointment_id']) as $b) {
            $put('booking', $b->ghl_invoice_id, 'booking', $b->id);
            $put('booking', $b->ghl_booking_id, 'booking', $b->id);
            $put('booking', $b->ghl_appointment_id, 'booking', $b->id);
        }

        foreach (EngageProductTransaction::query()->get(['id', 'ghl_invoice_id']) as $t) {
            $put('product_transaction', $t->ghl_invoice_id, 'product_transaction', $t->id);
        }

        // A campsite transaction is shown through its booking when it has one.
        foreach (EngageRentalTransaction::query()->get(['id', 'booking_id', 'ghl_invoice_id', 'ghl_booking_id']) as $t) {
            $target = $t->booking_id ? 'booking:'.$t->booking_id : 'campsite_transaction:'.$t->id;
            [$type, $id] = explode(':', $target, 2);
            $put('campsite_transaction', $t->ghl_invoice_id, $type, $id);
            $put('campsite_transaction', $t->ghl_booking_id, $type, $id);
        }

        foreach (EngageCustomerLocation::query()->whereNotNull('ghl_contact_id')->get(['customer_id', 'ghl_contact_id']) as $l) {
            $put('customer', $l->ghl_contact_id, 'customer', $l->customer_id);
        }

        foreach (EngageProductRental::query()->get(['id', 'ghl_id', 'ghl_product_id']) as $r) {
            $put('campsite', $r->ghl_id, 'campsite', $r->id);
            $put('campsite', $r->ghl_product_id, 'campsite', $r->id);
        }
    }

    /** @return array<string, string> log id => "type:id" for rows not linked yet */
    private function findLinks(): array
    {
        $links = [];

        WebhookLog::query()->whereNull('loggable_type')->orderBy('id')
            ->chunk(500, function ($logs) use (&$links) {
                foreach ($logs as $log) {
                    $link = $this->resolveLink($log);
                    if ($link) {
                        $links[$log->id] = $link;
                    }
                }
            });

        return $links;
    }

    private function resolveLink(WebhookLog $log): ?string
    {
        $payload = is_array($log->payload) ? $log->payload : [];

        if ($log->isOutbound()) {
            // Our own calls: any id in the request or the reply, best record first.
            return $this->firstMatch($this->collectIds($payload), ['booking', 'product_transaction', 'campsite_transaction', 'customer', 'campsite']);
        }

        $event = (string) $log->event_type;

        // Inbound: only the event's own id counts, so an unknown invoice is not
        // kept just because its contact or line items exist here.
        if (str_starts_with($event, 'Invoice')) {
            $invoice = is_array($payload['invoice'] ?? null) ? $payload['invoice'] : $payload;
            $ids = array_filter([$invoice['_id'] ?? null, $payload['invoiceId'] ?? null, $invoice['id'] ?? null], 'is_string');

            return $this->firstMatch($ids, ['booking', 'product_transaction', 'campsite_transaction']);
        }

        if (str_starts_with($event, 'Appointment')) {
            $appointment = is_array($payload['appointment'] ?? null) ? $payload['appointment'] : $payload;
            $ids = array_filter([
                $appointment['id'] ?? null,
                $appointment['serviceBookingId'] ?? null,
                $payload['appointmentId'] ?? null,
                $payload['id'] ?? null,
            ], 'is_string');

            return $this->firstMatch($ids, ['booking', 'campsite_transaction']);
        }

        if (str_starts_with($event, 'contact.')) {
            $contact = is_array($payload['contact'] ?? null) ? $payload['contact'] : $payload;

            return $this->firstMatch(array_filter([$contact['id'] ?? null], 'is_string'), ['customer']);
        }

        if (str_starts_with($event, 'opportunity.')) {
            $opportunity = is_array($payload['opportunity'] ?? null) ? $payload['opportunity'] : $payload;
            $id = $opportunity['id'] ?? null;
            $booking = is_string($id) ? EngageBooking::where('ghl_opportunity_id', $id)->value('id') : null;

            return $booking ? 'booking:'.$booking : null;
        }

        return null;
    }

    private function firstMatch(array $ids, array $mapOrder): ?string
    {
        foreach ($mapOrder as $map) {
            foreach ($ids as $id) {
                if (isset($this->maps[$map][$id])) {
                    return $this->maps[$map][$id];
                }
            }
        }

        return null;
    }

    private function collectIds(array $payload, int $depth = 0): array
    {
        $ids = [];

        foreach ($payload as $key => $value) {
            if (is_string($value) && $value !== '' && in_array(strtolower((string) $key), self::ID_KEYS, true)) {
                $ids[] = $value;
            } elseif (is_array($value) && $depth < 6) {
                array_push($ids, ...$this->collectIds($value, $depth + 1));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Later copies of the same inbound webhook (same event and payload, logged
     * within a few seconds). Keeps the linked copy if only one is linked, else
     * the first one.
     *
     * @return string[] log ids
     */
    private function findDuplicates(array $links): array
    {
        $payloadText = DB::getDriverName() === 'pgsql' ? 'md5(payload::text)' : 'payload';
        $rows = DB::table('webhook_logs')
            ->where('event_type', 'not like', WebhookLog::OUTBOUND_PREFIX.'%')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'event_type', 'created_at', 'loggable_type', DB::raw("{$payloadText} as fingerprint")]);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->event_type.'|'.$row->fingerprint][] = $row;
        }

        $duplicates = [];
        foreach ($groups as $group) {
            $cluster = [];
            foreach ($group as $row) {
                $previous = end($cluster);
                if ($previous && strtotime($row->created_at) - strtotime($previous->created_at) > self::DUPLICATE_WINDOW_SECONDS) {
                    array_push($duplicates, ...$this->extraCopies($cluster, $links));
                    $cluster = [];
                }
                $cluster[] = $row;
            }
            array_push($duplicates, ...$this->extraCopies($cluster, $links));
        }

        return $duplicates;
    }

    private function extraCopies(array $cluster, array $links): array
    {
        if (count($cluster) < 2) {
            return [];
        }

        $keep = collect($cluster)->first(fn ($row) => $row->loggable_type || isset($links[$row->id])) ?? $cluster[0];

        return collect($cluster)->reject(fn ($row) => $row->id === $keep->id)->pluck('id')->all();
    }

    /** @return string[] inbound log ids linked to nothing, older than $keepDays */
    private function findUnlinkedInbound(array $links, array $duplicates, int $keepDays): array
    {
        $skip = array_flip($duplicates);

        return DB::table('webhook_logs')
            ->whereNull('loggable_type')
            ->where('event_type', 'not like', WebhookLog::OUTBOUND_PREFIX.'%')
            ->where('created_at', '<', now()->subDays($keepDays))
            ->pluck('id')
            ->reject(fn ($id) => isset($links[$id]) || isset($skip[$id]))
            ->values()
            ->all();
    }
}
