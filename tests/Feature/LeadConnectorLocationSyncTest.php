<?php

namespace Tests\Feature;

use App\Integrations\GHL\GhlClient;
use App\Models\Country;
use App\Models\OrganizationSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class LeadConnectorLocationSyncTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private function fakeLocation(array $location): void
    {
        $client = Mockery::mock(GhlClient::class);
        $client->shouldReceive('get')->andReturnUsing(function (string $endpoint) use ($location) {
            $this->assertStringStartsWith('locations/', $endpoint);

            return ['location' => $location];
        });
        $this->app->instance(GhlClient::class, $client);
    }

    private function sampleLocation(): array
    {
        return [
            'name' => 'Pines LLC',
            'email' => 'top@pines.test',
            'phone' => '+1 555 014 2890',
            'timezone' => 'America/Los_Angeles',
            'business' => [
                'name' => 'Whispering Pines',
                'address' => '1240 Pine Ridge Trail',
                'city' => 'Pinecrest',
                'state' => 'CA',
                'postalCode' => '95364',
                'country' => 'US',
                'email' => 'hello@pines.test',
                'logoUrl' => 'https://cdn.example.test/logo.png',
            ],
        ];
    }

    public function test_preview_compares_lead_connector_values_without_saving(): void
    {
        $org = $this->makeOrganization('Old Name');
        $owner = $this->makeUser(['owner'], $org);
        $this->fakeLocation($this->sampleLocation());

        $this->getJson('/api/v1/organization/settings/lead-connector', $this->tokenFor($owner, $org))
            ->assertOk()
            ->assertJsonPath('data.fields.name.lead_connector', 'Whispering Pines')
            ->assertJsonPath('data.fields.name.changed', true)
            ->assertJsonPath('data.fields.email.lead_connector', 'hello@pines.test')
            ->assertJsonPath('data.fields.phone.lead_connector', '+1 555 014 2890')
            ->assertJsonPath('data.fields.address.lead_connector', '1240 Pine Ridge Trail, Pinecrest, CA, 95364, US')
            ->assertJsonPath('data.fields.logo.lead_connector', 'https://cdn.example.test/logo.png');

        $this->assertStringStartsWith('Old Name', $org->fresh()->name);
    }

    public function test_apply_copies_only_the_chosen_fields_and_imports_the_logo(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.example.test/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        Country::query()->firstOrCreate(['iso2' => 'US'], ['name' => 'United States', 'is_active' => true]);
        $org = $this->makeOrganization('Old Name');
        $org->update(['business_phone' => '111 111 1111']);
        $owner = $this->makeUser(['admin'], $org);
        $this->fakeLocation($this->sampleLocation());

        $this->postJson('/api/v1/organization/settings/lead-connector', [
            'fields' => ['name', 'address', 'timezone', 'logo'],
        ], $this->tokenFor($owner, $org))
            ->assertOk()
            ->assertJsonPath('data.applied', ['name', 'address', 'timezone', 'logo']);

        $org->refresh();
        $this->assertSame('Whispering Pines', $org->name);
        $this->assertSame('Pinecrest', $org->city);
        $this->assertSame('United States', $org->country);
        $this->assertSame('US', $org->business_country_code);
        $this->assertSame('America/Los_Angeles', $org->timezone);
        $this->assertSame('111 111 1111', $org->business_phone); // not chosen, untouched

        $logo = OrganizationSetting::forOrganization($org->id)->logo_light_url;
        $this->assertNotNull($logo);
        Storage::disk('public')->assertExists('organization-branding/'.basename(parse_url($logo, PHP_URL_PATH)));
    }

    public function test_staff_cannot_sync(): void
    {
        $org = $this->makeOrganization();
        $staff = $this->makeUser(['staff'], $org);

        $this->getJson('/api/v1/organization/settings/lead-connector', $this->tokenFor($staff, $org))->assertForbidden();
        $this->postJson('/api/v1/organization/settings/lead-connector', ['fields' => ['name']], $this->tokenFor($staff, $org))->assertForbidden();
    }

    public function test_a_missing_scope_gives_a_clear_message(): void
    {
        $org = $this->makeOrganization();
        $owner = $this->makeUser(['owner'], $org);
        $client = Mockery::mock(GhlClient::class);
        $client->shouldReceive('get')->andThrow(new \RuntimeException('GHL API error: 401 - {"message":"The token is not authorized for this scope."}'));
        $this->app->instance(GhlClient::class, $client);

        $this->getJson('/api/v1/organization/settings/lead-connector', $this->tokenFor($owner, $org))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'locations.readonly'));
    }

    public function test_unknown_fields_are_rejected(): void
    {
        $org = $this->makeOrganization();
        $owner = $this->makeUser(['owner'], $org);

        $this->postJson('/api/v1/organization/settings/lead-connector', ['fields' => ['password']], $this->tokenFor($owner, $org))
            ->assertStatus(422);
    }
}
