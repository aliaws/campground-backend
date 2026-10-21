<?php

namespace App\Services\Billing;

use App\Models\EngageOrganizationLocation;
use App\Models\OrganizationUsageSnapshot;
use App\Models\UsageMeasurementRun;
use App\Models\User;
use App\Support\Billing\UsageMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes the daily usage snapshot for every organization (or a chosen few):
 * record counts with one grouped query per metric, one file-storage scan and
 * one database estimate shared by all organizations. One snapshot per
 * organization per UTC date — a second run on the same date updates it.
 *
 * Only one run at a time (cache lock). A metric that fails is stored as null
 * with status "failed", never as zero.
 */
class UsageSnapshotService
{
    public const LOCK_KEY = 'usage:snapshot:run';

    public function __construct(
        private readonly FileStorageMeasurer $files,
        private readonly DatabaseStorageEstimator $database,
    ) {}

    /**
     * @param  list<string>|null  $organizationIds  null = every organization
     *
     * @throws UsageMeasurementInProgressException when another run holds the lock
     */
    public function run(?Carbon $date = null, string $triggeredBy = 'command', ?User $user = null, ?array $organizationIds = null): UsageMeasurementRun
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) config('usage.lock_seconds', 1800));
        if (! $lock->get()) {
            throw new UsageMeasurementInProgressException('A usage measurement is already running. Try again when it finishes.');
        }

        $date = ($date ?? now('UTC'))->copy()->utc()->startOfDay();
        $started = microtime(true);
        $run = UsageMeasurementRun::create([
            'snapshot_date' => $date->toDateString(),
            'triggered_by' => $triggeredBy,
            'user_id' => $user?->id,
            'status' => UsageMeasurementRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $organizations = EngageOrganizationLocation::query()
                ->when($organizationIds !== null, fn ($q) => $q->whereIn('id', $organizationIds))
                ->pluck('id')->all();

            $runErrors = [];
            [$counts, $countStatus, $countErrors] = $this->measureCounts($organizations);
            $runErrors = array_merge($runErrors, $countErrors);

            $files = $this->files->measure();
            $runErrors = array_merge($runErrors, array_map(fn ($e) => "File storage: {$e}", $files['errors']));

            $database = $this->database->estimate();
            $runErrors = array_merge($runErrors, array_map(fn ($e) => "Database storage: {$e}", $database['errors']));

            $partialRun = false;
            foreach ($organizations as $orgId) {
                $metrics = [];
                $status = [];
                foreach ([UsageMetrics::MEMBERS, UsageMetrics::CUSTOMERS, UsageMetrics::BOOKINGS, UsageMetrics::PRODUCTS, UsageMetrics::RENTAL_UNITS, UsageMetrics::TRANSACTIONS] as $key) {
                    $metrics[$key] = $countStatus[$key] === 'ok' ? (int) ($counts[$key][$orgId] ?? 0) : null;
                    $status[$key] = $countStatus[$key];
                }

                $fileData = $files['organizations'][$orgId] ?? ['bytes' => 0, 'files' => 0, 'shared_files' => 0, 'categories' => []];
                $metrics[UsageMetrics::FILE_STORAGE] = $files['available'] ? $this->toGb($fileData['bytes']) : null;
                $status[UsageMetrics::FILE_STORAGE] = ! $files['available'] ? 'failed' : ($files['complete'] ? 'ok' : 'partial');

                $dbBytes = $database['organizations'][$orgId] ?? 0;
                $metrics[UsageMetrics::DATABASE_STORAGE] = $database['available'] ? $this->toGb($dbBytes) : null;
                $status[UsageMetrics::DATABASE_STORAGE] = ! $database['available'] ? 'failed' : ($database['complete'] ? 'ok' : 'partial');

                $complete = ! in_array('failed', $status, true) && ! in_array('partial', $status, true);
                $partialRun = $partialRun || ! $complete;

                OrganizationUsageSnapshot::updateOrCreate(
                    ['engage_organization_location_id' => $orgId, 'snapshot_date' => $date->toDateString()],
                    [
                        'metrics' => $metrics,
                        'metric_status' => $status,
                        'storage' => [
                            'file_bytes' => $files['available'] ? $fileData['bytes'] : null,
                            'file_count' => $files['available'] ? $fileData['files'] : null,
                            'shared_file_count' => $files['available'] ? $fileData['shared_files'] : null,
                            'file_categories' => $files['available'] ? $fileData['categories'] : null,
                            'database_bytes' => $database['available'] ? $dbBytes : null,
                        ],
                        'status' => $complete ? OrganizationUsageSnapshot::STATUS_COMPLETE : OrganizationUsageSnapshot::STATUS_PARTIAL,
                        'errors' => $complete ? null : $runErrors,
                        'measured_at' => now(),
                        'usage_measurement_run_id' => $run->id,
                    ]
                );
            }

            $run->update([
                'status' => $partialRun || $runErrors !== [] ? UsageMeasurementRun::STATUS_PARTIAL : UsageMeasurementRun::STATUS_COMPLETE,
                'organization_count' => count($organizations),
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'summary' => [
                    'files' => ['available' => $files['available'], 'complete' => $files['complete'], 'roots' => $files['roots'], 'summary' => $files['summary']],
                    'database' => ['available' => $database['available'], 'complete' => $database['complete'], 'summary' => $database['summary']],
                ],
                'errors' => $runErrors === [] ? null : $runErrors,
            ]);

            if ($runErrors !== []) {
                Log::warning('Usage snapshot finished with errors', ['run_id' => $run->id, 'errors' => $runErrors]);
            }
        } catch (\Throwable $e) {
            report($e);
            $run->update([
                'status' => UsageMeasurementRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'errors' => [$e->getMessage()],
            ]);
        } finally {
            $lock->release();
        }

        return $run->fresh();
    }

    public function isRunning(): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 1);
        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * Record counts per organization, one grouped query per metric.
     *
     * @return array{0: array<string, array<string, int>>, 1: array<string, string>, 2: list<string>}
     */
    private function measureCounts(array $organizationIds): array
    {
        $org = 'engage_organization_location_id';
        $queries = [
            // Staff accounts linked to the organization (not customer logins).
            UsageMetrics::MEMBERS => fn () => DB::table('engage_users_locations as l')
                ->join('engage_users as u', 'u.id', '=', 'l.user_id')
                ->whereRaw("jsonb_exists_any(u.roles::jsonb, array['owner','admin','staff'])")
                ->groupBy("l.{$org}")
                ->selectRaw("l.{$org} as org, COUNT(DISTINCT l.user_id) as total"),
            UsageMetrics::CUSTOMERS => fn () => DB::table('engage_customers_locations as l')
                ->join('engage_customers as c', 'c.id', '=', 'l.customer_id')
                ->whereNull('c.deleted_at')
                ->groupBy("l.{$org}")
                ->selectRaw("l.{$org} as org, COUNT(DISTINCT l.customer_id) as total"),
            UsageMetrics::BOOKINGS => fn () => DB::table('engage_bookings')
                ->groupBy($org)->selectRaw("{$org} as org, COUNT(*) as total"),
            UsageMetrics::PRODUCTS => fn () => DB::table('engage_products')
                ->whereNull('deleted_at')->whereNull('product_rental_id')
                ->groupBy($org)->selectRaw("{$org} as org, COUNT(*) as total"),
            UsageMetrics::RENTAL_UNITS => fn () => DB::table('engage_product_rentals as r')
                ->join('engage_products as p', 'p.id', '=', 'r.product_id')
                ->whereNull('p.deleted_at')->where('r.is_active', true)
                ->groupBy("p.{$org}")
                ->selectRaw("p.{$org} as org, COUNT(*) as total"),
            UsageMetrics::TRANSACTIONS => fn () => DB::query()->fromSub(
                DB::table('engage_rental_transactions')->select($org)
                    ->unionAll(DB::table('engage_product_transactions')->select($org)),
                't'
            )->groupBy($org)->selectRaw("{$org} as org, COUNT(*) as total"),
        ];

        $counts = [];
        $status = [];
        $errors = [];
        foreach ($queries as $key => $query) {
            try {
                $counts[$key] = $query()->get()->pluck('total', 'org')->map(fn ($v) => (int) $v)->all();
                $status[$key] = 'ok';
            } catch (\Throwable $e) {
                report($e);
                $counts[$key] = [];
                $status[$key] = 'failed';
                $errors[] = UsageMetrics::get($key)['label'].": {$e->getMessage()}";
            }
        }

        return [$counts, $status, $errors];
    }

    private function toGb(int|float $bytes): string
    {
        return number_format($bytes / UsageMetrics::BYTES_PER_GB, 9, '.', '');
    }
}
