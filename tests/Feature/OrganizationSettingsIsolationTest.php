<?php

namespace Tests\Feature;

use App\Integrations\GHL\GhlClient;
use App\Models\OrganizationSetting;
use App\Services\Billing\FileStorageMeasurer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * White-label step D8: two campgrounds with different settings never see or
 * change each other's, including a user who belongs to both.
 */
class OrganizationSettingsIsolationTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    public function test_two_campgrounds_keep_their_own_settings(): void
    {
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Bravo');
        $ownerA = $this->makeUser(['owner'], $a);
        $ownerB = $this->makeUser(['owner'], $b);

        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'Alpha POS', 'accent_color' => '#b45309', 'currency' => 'CAD', 'support_email' => 'help@alpha.test'], $this->tokenFor($ownerA, $a))->assertOk();
        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'Bravo POS', 'accent_color' => '#1d4ed8', 'currency' => 'EUR', 'show_footer_attribution' => false], $this->tokenFor($ownerB, $b))->assertOk();

        $this->getJson('/api/v1/organization/settings', $this->tokenFor($ownerA, $a))
            ->assertJsonPath('data.brand.name', $a->name)
            ->assertJsonPath('data.brand.subtitle', 'Alpha POS')
            ->assertJsonPath('data.appearance.accent_color', '#b45309')
            ->assertJsonPath('data.receipts.currency', 'CAD')
            ->assertJsonPath('data.support.email', 'help@alpha.test')
            ->assertJsonPath('data.support.show_footer_attribution', true);

        $this->getJson('/api/v1/organization/settings', $this->tokenFor($ownerB, $b))
            ->assertJsonPath('data.brand.name', $b->name)
            ->assertJsonPath('data.brand.subtitle', 'Bravo POS')
            ->assertJsonPath('data.appearance.accent_color', '#1d4ed8')
            ->assertJsonPath('data.receipts.currency', 'EUR')
            ->assertJsonPath('data.support.email', null)
            ->assertJsonPath('data.support.show_footer_attribution', false);

        $this->assertSame(1, OrganizationSetting::where('engage_organization_location_id', $a->id)->count());
        $this->assertSame(1, OrganizationSetting::where('engage_organization_location_id', $b->id)->count());
    }

    public function test_a_user_in_both_campgrounds_sees_the_selected_ones_settings(): void
    {
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Bravo');
        $owner = $this->makeUser(['owner'], $a);
        $owner->attachLocation($b->id);

        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'Alpha POS'], $this->tokenFor($owner, $a))->assertOk();
        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'Bravo POS'], $this->tokenFor($owner, $b))->assertOk();

        $this->getJson('/api/v1/organization/settings', $this->tokenFor($owner, $a))->assertJsonPath('data.brand.subtitle', 'Alpha POS');
        $this->getJson('/api/v1/organization/settings', $this->tokenFor($owner, $b))->assertJsonPath('data.brand.subtitle', 'Bravo POS');
    }

    public function test_staff_only_reads_their_own_campground(): void
    {
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Bravo');
        $ownerA = $this->makeUser(['owner'], $a);
        $staffB = $this->makeUser(['staff'], $b);
        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'Alpha only'], $this->tokenFor($ownerA, $a))->assertOk();

        // A staff member of B asking with B's session gets B (defaults), never A.
        $this->getJson('/api/v1/organization/settings', $this->tokenFor($staffB, $b))
            ->assertOk()
            ->assertJsonPath('data.brand.name', $b->name)
            ->assertJsonPath('data.brand.subtitle', null);

        // A session claiming A for a user who isn't linked to A falls back to their own campground.
        $this->getJson('/api/v1/organization/settings', $this->tokenFor($staffB, $a))
            ->assertJsonPath('data.brand.subtitle', fn ($v) => $v !== 'Alpha only');
    }

    public function test_images_and_storage_billing_stay_with_their_campground(): void
    {
        Storage::fake('public');
        // The billing measurer reads the disk folder from config, which fake() doesn't change.
        config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Bravo');
        $ownerA = $this->makeUser(['owner'], $a);
        $ownerB = $this->makeUser(['owner'], $b);

        $this->post('/api/v1/organization/settings/image', ['type' => 'logo_light', 'image' => UploadedFile::fake()->image('a.png', 300, 120)], $this->tokenFor($ownerA, $a))->assertOk();
        $this->post('/api/v1/organization/settings/image', ['type' => 'favicon', 'image' => UploadedFile::fake()->image('b.png', 64, 64)], $this->tokenFor($ownerB, $b))->assertOk();

        $settingsA = OrganizationSetting::forOrganization($a->id);
        $settingsB = OrganizationSetting::forOrganization($b->id);
        $this->assertNotNull($settingsA->logo_light_url);
        $this->assertNull($settingsA->favicon_url);
        $this->assertNull($settingsB->logo_light_url);
        $this->assertNotNull($settingsB->favicon_url);

        // B removing its favicon leaves A's logo file alone.
        $this->deleteJson('/api/v1/organization/settings/image', ['type' => 'favicon'], $this->tokenFor($ownerB, $b))->assertOk();
        Storage::disk('public')->assertExists('organization-branding/'.basename(parse_url($settingsA->logo_light_url, PHP_URL_PATH)));

        $result = app(FileStorageMeasurer::class)->measure();
        $this->assertGreaterThan(0, $result['organizations'][$a->id]['categories']['branding_images'] ?? 0);
        $this->assertSame(0, (int) ($result['organizations'][$b->id]['categories']['branding_images'] ?? 0));
    }

    public function test_sync_from_highlevel_reads_the_selected_campgrounds_own_location(): void
    {
        $a = $this->makeOrganization('Alpha');
        $b = $this->makeOrganization('Bravo');
        $owner = $this->makeUser(['owner'], $a);
        $owner->attachLocation($b->id);

        $requested = [];
        $client = Mockery::mock(GhlClient::class);
        $client->shouldReceive('get')->andReturnUsing(function (string $endpoint) use (&$requested) {
            $requested[] = $endpoint;

            return ['location' => ['name' => 'Remote']];
        });
        $this->app->instance(GhlClient::class, $client);

        $this->getJson('/api/v1/organization/settings/lead-connector', $this->tokenFor($owner, $b))->assertOk();
        $this->getJson('/api/v1/organization/settings/lead-connector', $this->tokenFor($owner, $a))->assertOk();

        $this->assertSame(["locations/{$b->engage_location_id}", "locations/{$a->engage_location_id}"], $requested);
    }
}
