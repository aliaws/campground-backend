<?php

namespace Tests\Feature\Billing;

use App\Models\OrganizationUsageSnapshot;
use App\Models\UsageMeasurementRun;
use App\Services\Billing\DatabaseStorageEstimator;
use App\Services\Billing\UsageMeasurementInProgressException;
use App\Services\Billing\UsageSnapshotService;
use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class UsageSnapshotTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $root = storage_path('framework/testing/usage-'.Str::random(8));
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.public.root' => $root, 'usage.storage.disks' => ['public']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('filesystems.disks.public.root'));
        parent::tearDown();
    }

    private function snapshot(string $orgId, string $date): OrganizationUsageSnapshot
    {
        return OrganizationUsageSnapshot::where('engage_organization_location_id', $orgId)->whereDate('snapshot_date', $date)->firstOrFail();
    }

    public function test_counts_are_isolated_per_organization(): void
    {
        $a = $this->makeOrganization('A');
        $b = $this->makeOrganization('B');

        $this->makeUser(['owner'], $a);
        $this->makeUser(['staff'], $a);
        $shared = $this->makeUser(['admin'], $a);
        $shared->attachLocation($b->id);
        $this->makeUser(['customer'], $a); // a customer login is not a member

        $customerA = $this->makeCustomer([$a]);
        $customerBoth = $this->makeCustomer([$a, $b]);
        $this->makeCustomer([$b], ['deleted_at' => now()]); // archived: not counted

        $productA = $this->makeProduct($a);
        $this->makeProduct($a, ['deleted_at' => now()]);
        $productB = $this->makeProduct($b);
        $rentalProduct = $this->makeProduct($b);
        foreach ([true, true, false] as $active) {
            DB::table('engage_product_rentals')->insert(['id' => Str::lower((string) Str::ulid()), 'name' => 'Site', 'product_id' => $rentalProduct, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('engage_products')->where('id', $rentalProduct)->update(['product_rental_id' => DB::table('engage_product_rentals')->where('product_id', $rentalProduct)->value('id')]);

        $this->makeBooking($a, $customerA, $productA);
        $this->makeBooking($a, $customerBoth, $productA);
        $bookingB = $this->makeBooking($b, $customerBoth, $productB);
        DB::table('engage_rental_transactions')->insert(['id' => Str::lower((string) Str::ulid()), 'amount' => 10, 'status' => 'paid', 'booking_id' => $bookingB, 'engage_organization_location_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('engage_product_transactions')->insert(['id' => Str::lower((string) Str::ulid()), 'amount' => 5, 'status' => 'paid', 'engage_organization_location_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);

        $run = app(UsageSnapshotService::class)->run(Carbon::parse('2026-10-20', 'UTC'), 'test', null, [$a->id, $b->id]);

        $this->assertSame(2, $run->organization_count);
        $snapA = $this->snapshot($a->id, '2026-10-20');
        $snapB = $this->snapshot($b->id, '2026-10-20');

        $this->assertSame(3, $snapA->metrics[UsageMetrics::MEMBERS]);
        $this->assertSame(1, $snapB->metrics[UsageMetrics::MEMBERS]);
        $this->assertSame(2, $snapA->metrics[UsageMetrics::CUSTOMERS]);
        $this->assertSame(1, $snapB->metrics[UsageMetrics::CUSTOMERS]);
        $this->assertSame(2, $snapA->metrics[UsageMetrics::BOOKINGS]);
        $this->assertSame(1, $snapB->metrics[UsageMetrics::BOOKINGS]);
        $this->assertSame(1, $snapA->metrics[UsageMetrics::PRODUCTS]);
        $this->assertSame(1, $snapB->metrics[UsageMetrics::PRODUCTS]); // the rental product is not a store product
        $this->assertSame(0, $snapA->metrics[UsageMetrics::RENTAL_UNITS]);
        $this->assertSame(2, $snapB->metrics[UsageMetrics::RENTAL_UNITS]);
        $this->assertSame(0, $snapA->metrics[UsageMetrics::TRANSACTIONS]);
        $this->assertSame(2, $snapB->metrics[UsageMetrics::TRANSACTIONS]);
        $this->assertGreaterThan(0, (float) $snapA->metrics[UsageMetrics::DATABASE_STORAGE]);
    }

    public function test_one_snapshot_per_organization_per_date_and_reruns_update_it(): void
    {
        $a = $this->makeOrganization('A');
        $service = app(UsageSnapshotService::class);
        $date = Carbon::parse('2026-10-20', 'UTC');

        $service->run($date, 'test', null, [$a->id]);
        $this->makeUser(['staff'], $a);
        $service->run($date, 'test', null, [$a->id]);

        $this->assertSame(1, OrganizationUsageSnapshot::where('engage_organization_location_id', $a->id)->count());
        $this->assertSame(1, $this->snapshot($a->id, '2026-10-20')->metrics[UsageMetrics::MEMBERS]);

        $service->run(Carbon::parse('2026-10-21', 'UTC'), 'test', null, [$a->id]);
        $this->assertSame(2, OrganizationUsageSnapshot::where('engage_organization_location_id', $a->id)->count());
    }

    public function test_a_second_run_is_refused_while_one_holds_the_lock(): void
    {
        $lock = Cache::lock(UsageSnapshotService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(UsageMeasurementInProgressException::class);
            app(UsageSnapshotService::class)->run(null, 'test');
        } finally {
            $lock->release();
        }
    }

    public function test_run_records_status_and_releases_the_lock(): void
    {
        $a = $this->makeOrganization('A');

        $run = app(UsageSnapshotService::class)->run(null, 'test', null, [$a->id]);

        $this->assertContains($run->status, [UsageMeasurementRun::STATUS_COMPLETE, UsageMeasurementRun::STATUS_PARTIAL]);
        $this->assertNotNull($run->finished_at);
        $this->assertArrayHasKey('files', $run->summary);
        $this->assertFalse(app(UsageSnapshotService::class)->isRunning());
    }

    public function test_command_takes_a_snapshot_for_the_given_date(): void
    {
        $a = $this->makeOrganization('A');

        $this->artisan('usage:snapshot', ['--date' => '2026-09-30', '--organization' => [$a->id]])->assertExitCode(0);

        $this->assertSame('2026-09-30', $this->snapshot($a->id, '2026-09-30')->snapshot_date->toDateString());
        $this->artisan('usage:snapshot', ['--date' => '30-09-2026'])->assertExitCode(2);
    }

    public function test_database_estimate_is_attributed_per_organization_without_double_counting(): void
    {
        $a = $this->makeOrganization('A');
        $b = $this->makeOrganization('B');
        $customer = $this->makeCustomer([$a]);
        $product = $this->makeProduct($a, ['description' => str_repeat('long text ', 400)]);
        for ($i = 0; $i < 5; $i++) {
            $this->makeBooking($a, $customer, $product);
        }
        $this->makeProduct($b);

        $before = DB::table('engage_bookings')->count();
        $result = app(DatabaseStorageEstimator::class)->estimate();

        $this->assertTrue($result['available']);
        $this->assertGreaterThan($result['organizations'][$b->id] ?? 0, $result['organizations'][$a->id]);
        $this->assertLessThanOrEqual($result['summary']['database_bytes'], $result['summary']['attributed_bytes']);
        $tables = $result['summary']['tables'];
        $this->assertSame('direct', $tables['engage_bookings']['strategy']);
        $this->assertSame('child', $tables['engage_product_rentals']['strategy']);
        $this->assertSame('shared', $tables['engage_customers']['strategy']);
        $this->assertSame('excluded', $tables['organization_usage_snapshots']['strategy']);
        foreach ($tables as $table) {
            $this->assertLessThanOrEqual($table['total_bytes'], $table['attributed_bytes']);
        }
        $this->assertSame($before, DB::table('engage_bookings')->count()); // read-only
    }
}
