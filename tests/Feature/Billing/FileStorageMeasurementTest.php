<?php

namespace Tests\Feature\Billing;

use App\Models\OrganizationUsageSnapshot;
use App\Services\Billing\FileStorageMeasurer;
use App\Services\Billing\UsageSnapshotService;
use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * File storage is measured against a temporary public/private disk with real
 * files, so sizes, hard links, symlinks and unreadable folders are genuine.
 */
class FileStorageMeasurementTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private string $public;

    private string $private;

    protected function setUp(): void
    {
        parent::setUp();
        $base = storage_path('framework/testing/usage-'.Str::random(8));
        $this->public = $base.'/public';
        $this->private = $base.'/private';
        File::ensureDirectoryExists($this->public);
        File::ensureDirectoryExists($this->private);
        config([
            'filesystems.disks.public.root' => $this->public,
            'filesystems.disks.local.root' => $this->private,
            'usage.storage.disks' => ['public', 'local'],
        ]);

        // Leftover rows in the shared test database must not reference our files.
        DB::table('cms_pages')->update(['content' => json_encode([])]);
        DB::table('engage_users')->update(['avatar_url' => null]);
        DB::table('engage_products')->update(['images' => null, 'ghl_image_url' => null]);
        DB::table('engage_categories')->update(['image' => null]);
        DB::table('site_maps')->update(['image_url' => null]);
        DB::table('site_map_icon_types')->update(['image_url' => null]);
        DB::table('amenities')->update(['icon' => null]);
        DB::table('features')->update(['icon' => null]);
        DB::table('engage_organization_locations')->update(['business_information' => null]);
    }

    protected function tearDown(): void
    {
        @chmod($this->public.'/locked', 0755);
        File::deleteDirectory(dirname($this->public));
        parent::tearDown();
    }

    private function file(string $path, int $bytes, string $disk = 'public'): string
    {
        $full = ($disk === 'public' ? $this->public : $this->private).'/'.$path;
        File::ensureDirectoryExists(dirname($full));
        file_put_contents($full, str_repeat('x', $bytes));

        return $full;
    }

    private function url(string $path, bool $absolute = true): string
    {
        return ($absolute ? 'http://localhost:8001' : '').'/storage/'.$path;
    }

    public function test_attributes_every_upload_type_to_its_owning_organization(): void
    {
        $a = $this->makeOrganization('A');
        $b = $this->makeOrganization('B');

        $this->file('products/shirt.jpg', 1000);
        $this->file('products/shirt-thumb.jpg', 100); // generated variant stored as its own file
        $this->file('products/site.jpg', 2000);
        $this->file('categories/cat.png', 300);
        $this->file('site-maps/map.jpg', 4000);
        $this->file('site-map-icons/icon.png', 50);
        $this->file('avatars/staff.png', 700);
        $this->file('org/about.png', 60);

        $this->makeProduct($a, ['images' => json_encode([
            ['url' => $this->url('products/shirt.jpg'), 'position' => 0],
            ['url' => $this->url('products/shirt-thumb.jpg'), 'position' => 1],
            ['url' => 'https://assets.cdn.example/remote.jpg', 'position' => 2], // external: not ours
        ])]);
        $this->makeProduct($a, ['product_rental_id' => null, 'images' => json_encode([['url' => $this->url('products/site.jpg', false)]])]);
        DB::table('engage_categories')->insert(['id' => Str::lower((string) Str::ulid()), 'name' => 'Cat', 'slug' => 'cat-'.Str::random(6), 'image' => $this->url('categories/cat.png'), 'engage_organization_location_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_maps')->insert(['id' => Str::lower((string) Str::ulid()), 'name' => 'Map', 'image_url' => $this->url('site-maps/map.jpg', false), 'engage_organization_location_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_map_icon_types')->insert(['id' => Str::lower((string) Str::ulid()), 'name' => 'Icon', 'image_url' => $this->url('site-map-icons/icon.png'), 'engage_organization_location_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->makeUser(['staff'], $a, ['avatar_url' => $this->url('avatars/staff.png')]);
        DB::table('engage_organization_locations')->where('id', $b->id)->update(['business_information' => json_encode(['html' => '<p><img src="'.$this->url('org/about.png').'"></p>'])]);

        $result = app(FileStorageMeasurer::class)->measure();

        $this->assertTrue($result['available'], implode('; ', $result['errors']));
        $this->assertTrue($result['complete'], implode('; ', $result['errors']));
        $this->assertSame(1000 + 100 + 2000 + 300 + 700, $result['organizations'][$a->id]['bytes']);
        $this->assertSame(5, $result['organizations'][$a->id]['files']);
        $this->assertSame(['category_images' => 300, 'product_images' => 3100, 'profile_images' => 700], $result['organizations'][$a->id]['categories']);
        $this->assertSame(4000 + 50 + 60, $result['organizations'][$b->id]['bytes']);
        $this->assertSame(['organization_content' => 60, 'site_map_icons' => 50, 'site_map_images' => 4000], $result['organizations'][$b->id]['categories']);
    }

    public function test_campsite_images_are_categorized_separately(): void
    {
        $a = $this->makeOrganization('A');
        $this->file('products/tent.jpg', 900);
        $productId = $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/tent.jpg')]])]);
        $rentalId = Str::lower((string) Str::ulid());
        DB::table('engage_product_rentals')->insert(['id' => $rentalId, 'name' => 'Tent', 'product_id' => $productId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('engage_products')->where('id', $productId)->update(['product_rental_id' => $rentalId]);

        $result = app(FileStorageMeasurer::class)->measure();

        $this->assertSame(['campsite_images' => 900], $result['organizations'][$a->id]['categories']);
    }

    public function test_duplicate_references_and_hard_links_count_one_physical_file_once(): void
    {
        $a = $this->makeOrganization('A');
        $full = $this->file('products/same.jpg', 1234);
        if (! @link($full, $this->public.'/products/same-link.jpg')) {
            $this->markTestSkipped('Hard links are not supported here.');
        }

        // Two products, absolute and relative URL, plus the hard-linked path.
        $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/same.jpg')]])]);
        $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/same.jpg', false)], ['url' => $this->url('products/same-link.jpg')]])]);

        $result = app(FileStorageMeasurer::class)->measure();

        $this->assertSame(1234, $result['organizations'][$a->id]['bytes']);
        $this->assertSame(1, $result['organizations'][$a->id]['files']);
        $this->assertSame(1, $result['summary']['scanned_files']);
    }

    public function test_a_file_shared_by_two_organizations_is_split_between_them(): void
    {
        $a = $this->makeOrganization('A');
        $b = $this->makeOrganization('B');
        $this->file('avatars/customer.png', 1000);
        $customerId = $this->makeCustomer([$a, $b]);
        $this->makeUser(['customer'], null, ['avatar_url' => $this->url('avatars/customer.png'), 'customer_id' => $customerId]);

        $result = app(FileStorageMeasurer::class)->measure();

        $this->assertSame(500, $result['organizations'][$a->id]['bytes']);
        $this->assertSame(500, $result['organizations'][$b->id]['bytes']);
        $this->assertSame(1, $result['organizations'][$a->id]['shared_files']);
        $this->assertSame(1000, $result['summary']['tenant_shared_bytes']);
    }

    public function test_platform_files_unattributed_files_and_excluded_files_are_never_billed(): void
    {
        $a = $this->makeOrganization('A');
        $this->file('cms-images/logo.png', 400);
        $this->file('amenity-icons/wifi.png', 30);
        $this->file('avatars/superadmin.png', 200);
        $this->file('products/orphan.jpg', 5000);
        $this->file('private-upload.pdf', 70, 'local');
        $this->file('products/.DS_Store', 9);
        $this->file('products/upload.part', 11);
        symlink('/etc/hostname', $this->public.'/products/escape-link');

        DB::table('cms_pages')->insert(['id' => Str::lower((string) Str::ulid()), 'slug' => 'usage-test-'.Str::random(6), 'title' => 'Test', 'content' => json_encode(['logo_url' => $this->url('cms-images/logo.png')]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('amenities')->insert(['id' => Str::lower((string) Str::ulid()), 'name' => 'Wifi', 'icon' => $this->url('amenity-icons/wifi.png', false), 'created_at' => now(), 'updated_at' => now()]);
        $this->makeUser(['superadmin'], null, ['avatar_url' => $this->url('avatars/superadmin.png')]);
        $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/missing.jpg')]])]);

        $result = app(FileStorageMeasurer::class)->measure();
        $summary = $result['summary'];

        $this->assertArrayNotHasKey($a->id, $result['organizations']);
        $this->assertSame(630, $summary['platform_shared_bytes']);
        $this->assertSame(5070, $summary['unattributed_bytes']); // orphan + file on the private disk
        $this->assertSame(2, $summary['unattributed_files']);
        $this->assertSame(2, $summary['excluded_files']);
        $this->assertSame(20, $summary['excluded_bytes']);
        $this->assertSame(1, $summary['skipped_links']);
        $this->assertSame(1, $summary['missing_references']);
        $this->assertSame(5700, $summary['scanned_bytes']);
    }

    public function test_an_unreadable_folder_marks_the_scan_incomplete(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: folder permissions are not enforced.');
        }
        $a = $this->makeOrganization('A');
        $this->file('products/ok.jpg', 100);
        $this->file('locked/secret.jpg', 100);
        chmod($this->public.'/locked', 0000);
        $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/ok.jpg')]])]);

        $result = app(FileStorageMeasurer::class)->measure();

        $this->assertTrue($result['available'], implode('; ', $result['errors']));
        $this->assertFalse($result['complete']);
        $this->assertSame('partial', $result['roots'][0]['status']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_a_failed_scan_is_stored_as_unmeasured_not_zero(): void
    {
        $a = $this->makeOrganization('A');
        config(['filesystems.disks.public' => null]);

        app(UsageSnapshotService::class)->run(now('UTC'), 'test', null, [$a->id]);

        $snapshot = OrganizationUsageSnapshot::where('engage_organization_location_id', $a->id)->firstOrFail();
        $this->assertNull($snapshot->metrics[UsageMetrics::FILE_STORAGE]);
        $this->assertSame('failed', $snapshot->metric_status[UsageMetrics::FILE_STORAGE]);
        $this->assertSame('partial', $snapshot->status);
    }

    public function test_snapshot_stores_file_storage_in_gb_with_byte_detail(): void
    {
        $a = $this->makeOrganization('A');
        $this->file('products/big.jpg', 2048);
        $this->makeProduct($a, ['images' => json_encode([['url' => $this->url('products/big.jpg')]])]);

        app(UsageSnapshotService::class)->run(now('UTC'), 'test', null, [$a->id]);

        $snapshot = OrganizationUsageSnapshot::where('engage_organization_location_id', $a->id)->firstOrFail();
        $this->assertSame(2048, $snapshot->storage['file_bytes']);
        $this->assertSame(1, $snapshot->storage['file_count']);
        $this->assertEqualsWithDelta(2048 / UsageMetrics::BYTES_PER_GB, (float) $snapshot->metrics[UsageMetrics::FILE_STORAGE], 1e-9);
        $this->assertSame('ok', $snapshot->metric_status[UsageMetrics::FILE_STORAGE]);
    }
}
