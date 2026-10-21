<?php

namespace App\Console\Commands;

use App\Services\Billing\UsageMeasurementInProgressException;
use App\Services\Billing\UsageSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily usage snapshot for usage-based billing (scheduled at 01:15 UTC in
 * bootstrap/app.php). Re-running on the same date updates that date's
 * snapshots. See docs/usage-billing.md.
 */
class TakeUsageSnapshot extends Command
{
    protected $signature = 'usage:snapshot
        {--date= : UTC date to record the snapshot under (YYYY-MM-DD, default today)}
        {--organization=* : Only these organization ids (default: all)}';

    protected $description = 'Measure each organization\'s usage (records, file storage, database storage) and store today\'s snapshot';

    public function handle(UsageSnapshotService $service): int
    {
        $date = null;
        if ($this->option('date')) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', (string) $this->option('date'), 'UTC')->startOfDay();
            } catch (\Throwable) {
                $this->error('--date must be YYYY-MM-DD.');

                return self::INVALID;
            }
        }

        $organizations = $this->option('organization') ?: null;

        try {
            $run = $service->run($date, 'command', null, $organizations);
        } catch (UsageMeasurementInProgressException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Usage snapshot {$run->snapshot_date->toDateString()}: {$run->status}, {$run->organization_count} organization(s), {$run->duration_ms} ms.");
        foreach ($run->errors ?? [] as $error) {
            $this->warn(" - {$error}");
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
