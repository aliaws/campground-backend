<?php

namespace Tests\Feature\Billing;

use App\Models\OrganizationUsageSnapshot;
use App\Services\Billing\MonthlyUsageAggregator;
use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class MonthlyUsageAggregatorTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function snap(string $orgId, string $date, array $metrics, array $status = []): void
    {
        $values = array_merge(array_fill_keys(UsageMetrics::keys(), 0), $metrics);
        OrganizationUsageSnapshot::create([
            'engage_organization_location_id' => $orgId,
            'snapshot_date' => $date,
            'metrics' => $values,
            'metric_status' => array_merge(array_map(fn ($v) => $v === null ? 'failed' : 'ok', $values), $status),
            'status' => 'complete',
            'measured_at' => $date.' 01:15:00',
        ]);
    }

    public function test_storage_is_averaged_members_maxed_and_counts_take_the_latest(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $org = $this->makeOrganization();
        $this->snap($org->id, '2026-09-30', ['bookings' => 999]); // other month: ignored
        $this->snap($org->id, '2026-10-01', ['file_storage' => '1', 'members' => 3, 'bookings' => 10]);
        $this->snap($org->id, '2026-10-15', ['file_storage' => '2', 'members' => 7, 'bookings' => 20]);
        $this->snap($org->id, '2026-10-31', ['file_storage' => '6', 'members' => 4, 'bookings' => 25]);

        $usage = app(MonthlyUsageAggregator::class)->forOrganization($org->id, '2026-10');

        $this->assertSame('3', $usage['metrics']['file_storage']['quantity']);
        $this->assertSame('7', $usage['metrics']['members']['quantity']);
        $this->assertSame('25', $usage['metrics']['bookings']['quantity']);
        $this->assertSame(3, $usage['snapshot_days']);
        $this->assertSame('complete', $usage['status']);
        $this->assertSame('2026-10-31', $usage['latest_snapshot_date']);
    }

    public function test_month_without_snapshots_falls_back_to_the_latest_earlier_one_and_is_stale(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $org = $this->makeOrganization();
        $this->snap($org->id, '2026-08-20', ['bookings' => 4]);
        $this->snap($org->id, '2026-09-10', ['bookings' => 8]);

        $usage = app(MonthlyUsageAggregator::class)->forOrganization($org->id, '2026-10');

        $this->assertSame('stale', $usage['status']);
        $this->assertSame('fallback', $usage['source']);
        $this->assertContains('fallback', $usage['flags']);
        $this->assertSame('8', $usage['metrics']['bookings']['quantity']);
        $this->assertSame('2026-09-10', $usage['latest_snapshot_date']);
    }

    public function test_no_snapshot_at_all_is_missing_not_zero(): void
    {
        $org = $this->makeOrganization();

        $usage = app(MonthlyUsageAggregator::class)->forOrganization($org->id, '2026-10');

        $this->assertSame('missing', $usage['status']);
        foreach ($usage['metrics'] as $metric) {
            $this->assertNull($metric['quantity']);
            $this->assertSame('missing', $metric['status']);
        }
    }

    public function test_current_month_is_stale_when_measurement_stopped(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $org = $this->makeOrganization();
        $this->snap($org->id, '2026-10-05', []);

        $usage = app(MonthlyUsageAggregator::class)->forOrganization($org->id, '2026-10');

        $this->assertSame('stale', $usage['status']);
        $this->assertSame('month', $usage['source']);
    }

    public function test_a_failed_metric_day_makes_the_month_partial(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $org = $this->makeOrganization();
        $this->snap($org->id, '2026-10-01', ['database_storage' => '0.5']);
        $this->snap($org->id, '2026-10-02', ['database_storage' => null]);

        $usage = app(MonthlyUsageAggregator::class)->forOrganization($org->id, '2026-10');

        $this->assertSame('partial', $usage['status']);
        $this->assertSame('partial', $usage['metrics']['database_storage']['status']);
        $this->assertSame('0.5', $usage['metrics']['database_storage']['quantity']); // average of the measured day only
        $this->assertSame('measured', $usage['metrics']['bookings']['status']);
    }
}
