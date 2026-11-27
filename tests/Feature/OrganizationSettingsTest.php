<?php

namespace Tests\Feature;

use App\Models\OrganizationSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    public function test_unsaved_settings_return_defaults_and_profile_values(): void
    {
        $org = $this->makeOrganization('Pines');
        $org->update(['business_email' => 'hello@pines.test', 'timezone' => 'America/Los_Angeles']);
        $staff = $this->makeUser(['staff'], $org);

        $this->getJson('/api/v1/organization/settings', $this->tokenFor($staff, $org))
            ->assertOk()
            ->assertJsonPath('data.saved', false)
            ->assertJsonPath('data.brand.name', $org->name)
            ->assertJsonPath('data.appearance.hide_empty_categories', true)
            ->assertJsonPath('data.receipts.currency', 'USD')
            ->assertJsonPath('data.support.show_footer_attribution', true)
            ->assertJsonPath('data.business.email', 'hello@pines.test')
            ->assertJsonPath('data.business.timezone', 'America/Los_Angeles');

        $this->assertSame(0, OrganizationSetting::where('engage_organization_location_id', $org->id)->count());
    }

    public function test_owner_saves_settings_and_only_their_organization_changes(): void
    {
        $org = $this->makeOrganization('A');
        $other = $this->makeOrganization('B');
        $owner = $this->makeUser(['owner'], $org);

        $this->putJson('/api/v1/organization/settings', [
            'brand_subtitle' => 'Lakeside camping',
            'accent_color' => '#1d7a62',
            'default_theme' => 'dark',
            'hide_empty_categories' => false,
            'tax_label' => 'Sales tax',
            'tax_rate' => 7.25,
            'currency' => 'cad',
            'support_email' => 'help@a.test',
            'show_footer_attribution' => false,
        ], $this->tokenFor($owner, $org))
            ->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.brand.subtitle', 'Lakeside camping')
            ->assertJsonPath('data.appearance.default_theme', 'dark')
            ->assertJsonPath('data.appearance.hide_empty_categories', false)
            ->assertJsonPath('data.receipts.tax_rate', 7.25)
            ->assertJsonPath('data.receipts.currency', 'CAD')
            ->assertJsonPath('data.support.show_footer_attribution', false);

        // A partial save keeps the other fields.
        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => null], $this->tokenFor($owner, $org))
            ->assertOk()
            ->assertJsonPath('data.brand.subtitle', null)
            ->assertJsonPath('data.receipts.tax_label', 'Sales tax');

        $this->assertSame(1, OrganizationSetting::where('engage_organization_location_id', $org->id)->count());
        $this->assertSame(0, OrganizationSetting::where('engage_organization_location_id', $other->id)->count());
    }

    public function test_staff_can_read_but_not_change(): void
    {
        $org = $this->makeOrganization();
        $staff = $this->makeUser(['staff'], $org);
        $headers = $this->tokenFor($staff, $org);

        $this->getJson('/api/v1/organization/settings', $headers)->assertOk();
        $this->putJson('/api/v1/organization/settings', ['brand_subtitle' => 'x'], $headers)->assertForbidden();
        $this->postJson('/api/v1/organization/settings/image', ['type' => 'logo_light'], $headers)->assertForbidden();
    }

    public function test_invalid_values_are_rejected(): void
    {
        $org = $this->makeOrganization();
        $owner = $this->makeUser(['owner'], $org);

        $this->putJson('/api/v1/organization/settings', [
            'accent_color' => 'green',
            'default_theme' => 'blue',
            'tax_rate' => 150,
            'currency' => 'DOLLARS',
            'support_url' => 'javascript:alert(1)',
            'support_email' => 'not-an-email',
        ], $this->tokenFor($owner, $org))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accent_color', 'default_theme', 'tax_rate', 'currency', 'support_url', 'support_email']);
    }

    public function test_image_upload_replaces_and_removes_the_old_file(): void
    {
        Storage::fake('public');
        $org = $this->makeOrganization();
        $owner = $this->makeUser(['admin'], $org);
        $headers = $this->tokenFor($owner, $org);

        $first = $this->post('/api/v1/organization/settings/image', [
            'type' => 'logo_light',
            'image' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], $headers)->assertOk()->json('data.brand.logo_light_url');
        $this->assertStringStartsWith('http', $first);
        $firstPath = 'organization-branding/'.basename(parse_url($first, PHP_URL_PATH));
        Storage::disk('public')->assertExists($firstPath);

        $second = $this->post('/api/v1/organization/settings/image', [
            'type' => 'logo_light',
            'image' => UploadedFile::fake()->image('logo2.png', 200, 80),
        ], $headers)->assertOk()->json('data.brand.logo_light_url');
        Storage::disk('public')->assertMissing($firstPath);

        $this->deleteJson('/api/v1/organization/settings/image', ['type' => 'logo_light'], $headers)
            ->assertOk()
            ->assertJsonPath('data.brand.logo_light_url', null);
        Storage::disk('public')->assertMissing('organization-branding/'.basename(parse_url($second, PHP_URL_PATH)));

        $this->post('/api/v1/organization/settings/image', [
            'type' => 'logo_dark',
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], $headers)->assertStatus(422);
    }
}
