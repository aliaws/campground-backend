<?php

namespace Tests\Feature\Billing;

use App\Models\OrganizationUsageSnapshot;
use App\Models\PricingPlan;
use App\Services\Billing\PricingPlanPdfService;
use App\Services\Billing\UsageSnapshotService;
use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class PricingBillingApiTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Growth '.Str::random(5),
            'description' => 'For growing campgrounds',
            'currency' => 'USD',
            'base_price' => '49.00',
            'metrics' => [
                ['key' => 'bookings', 'included_quantity' => 100, 'unit_price' => 5, 'billing_unit' => 100, 'metric_factor' => 1],
                ['key' => 'file_storage', 'included_quantity' => 1, 'unit_price' => '0.5', 'billing_unit' => 1, 'metric_factor' => 1],
            ],
        ], $overrides);
    }

    public function test_only_the_superadmin_can_reach_billing_endpoints(): void
    {
        $org = $this->makeOrganization();
        $plan = PricingPlan::default();
        $endpoints = [
            ['GET', '/api/v1/superadmin/pricing-plans'],
            ['POST', '/api/v1/superadmin/pricing-plans'],
            ['PUT', "/api/v1/superadmin/pricing-plans/{$plan->id}"],
            ['DELETE', "/api/v1/superadmin/pricing-plans/{$plan->id}"],
            ['POST', "/api/v1/superadmin/pricing-plans/{$plan->id}/make-default"],
            ['GET', '/api/v1/superadmin/pricing-plans/pdf'],
            ['GET', '/api/v1/superadmin/billing/organizations'],
            ['GET', "/api/v1/superadmin/billing/organizations/{$org->id}"],
            ['PUT', "/api/v1/superadmin/billing/organizations/{$org->id}/plan"],
            ['POST', '/api/v1/superadmin/billing/measure'],
            ['GET', '/api/v1/superadmin/billing/export'],
            ['GET', '/api/v1/superadmin/billing/status'],
            ['GET', '/api/v1/superadmin/billing/metrics'],
        ];

        foreach (['owner', 'admin', 'staff', 'customer'] as $role) {
            $headers = $this->tokenFor($this->makeUser([$role], $org), $org);
            foreach ($endpoints as [$method, $uri]) {
                $this->json($method, $uri, [], $headers)->assertForbidden();
            }
        }
        foreach ($endpoints as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        $this->assertNotNull(PricingPlan::find($plan->id));
    }

    public function test_superadmin_creates_and_updates_a_plan(): void
    {
        $headers = $this->superadminHeaders();

        $created = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(), $headers)->assertCreated();
        $id = $created->json('data.id');
        $metrics = collect($created->json('data.metrics'))->keyBy('key');
        $this->assertCount(count(UsageMetrics::keys()), $metrics);
        $this->assertSame('100', $metrics['bookings']['included_quantity']);
        $this->assertFalse($metrics['members']['configured']); // safe default
        $this->assertSame('0', $metrics['members']['unit_price']);

        $this->putJson("/api/v1/superadmin/pricing-plans/{$id}", ['base_price' => '59.50', 'metrics' => [
            ['key' => 'members', 'included_quantity' => 3, 'unit_price' => 4, 'billing_unit' => 1, 'metric_factor' => '1.25'],
        ]], $headers)->assertOk()->assertJsonPath('data.base_price', '59.50');

        $plan = PricingPlan::with('metrics')->find($id);
        $this->assertSame(3, $plan->metrics->count());
        $this->assertSame('1.2500', (string) $plan->metrics->firstWhere('metric_key', 'members')->metric_factor);
    }

    public function test_plan_validation(): void
    {
        $headers = $this->superadminHeaders();
        $cases = [
            'base_price' => ['base_price' => '-1'],
            'currency' => ['currency' => 'usd'],
            'metrics.0.billing_unit' => ['metrics' => [['key' => 'bookings', 'included_quantity' => 0, 'unit_price' => 1, 'billing_unit' => 0, 'metric_factor' => 1]]],
            'metrics.0.metric_factor' => ['metrics' => [['key' => 'bookings', 'included_quantity' => 0, 'unit_price' => 1, 'billing_unit' => 1, 'metric_factor' => 11]]],
            'metrics.0.unit_price' => ['metrics' => [['key' => 'bookings', 'included_quantity' => 0, 'unit_price' => -2, 'billing_unit' => 1, 'metric_factor' => 1]]],
            'metrics.0.included_quantity' => ['metrics' => [['key' => 'bookings', 'included_quantity' => -1, 'unit_price' => 1, 'billing_unit' => 1, 'metric_factor' => 1]]],
            'metrics.0.key' => ['metrics' => [['key' => 'active_users', 'included_quantity' => 0, 'unit_price' => 1, 'billing_unit' => 1, 'metric_factor' => 1]]],
        ];

        foreach ($cases as $field => $override) {
            $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload($override), $headers)
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $existing = PricingPlan::default()->name;
        $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['name' => $existing]), $headers)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_exactly_one_default_plan_and_default_rules(): void
    {
        $headers = $this->superadminHeaders();
        $oldDefault = PricingPlan::default();
        $id = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['is_active' => false]), $headers)->json('data.id');

        $this->postJson("/api/v1/superadmin/pricing-plans/{$id}/make-default", [], $headers)
            ->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.is_active', true);
        $this->assertSame(1, PricingPlan::where('is_default', true)->count());
        $this->assertFalse($oldDefault->fresh()->is_default);

        $this->postJson("/api/v1/superadmin/pricing-plans/{$id}/deactivate", [], $headers)->assertUnprocessable();
        $this->putJson("/api/v1/superadmin/pricing-plans/{$id}", ['is_active' => false], $headers)->assertUnprocessable();
        $this->deleteJson("/api/v1/superadmin/pricing-plans/{$id}", [], $headers)->assertUnprocessable();
        $this->assertTrue(PricingPlan::find($id)->is_active);

        // Sending is_default=false never leaves the platform without a default.
        $this->putJson("/api/v1/superadmin/pricing-plans/{$id}", ['is_default' => false], $headers)->assertOk();
        $this->assertSame(1, PricingPlan::where('is_default', true)->count());
    }

    public function test_plans_in_use_cannot_be_deleted_and_unused_plans_can(): void
    {
        $headers = $this->superadminHeaders();
        $org = $this->makeOrganization();
        $used = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(), $headers)->json('data.id');
        $unused = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(), $headers)->json('data.id');

        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_plan_id' => $used, 'pricing_factor' => '0.9'], $headers)->assertOk();

        $this->deleteJson("/api/v1/superadmin/pricing-plans/{$used}", [], $headers)->assertUnprocessable();
        $this->deleteJson("/api/v1/superadmin/pricing-plans/{$unused}", [], $headers)->assertOk();
        $this->assertNull(PricingPlan::find($unused));
        $this->assertSame($used, $org->fresh()->pricing_plan_id);
    }

    public function test_assigning_plans_and_factors(): void
    {
        $headers = $this->superadminHeaders();
        $org = $this->makeOrganization();
        $inactive = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['is_active' => false]), $headers)->json('data.id');

        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_plan_id' => $inactive, 'pricing_factor' => 1], $headers)->assertUnprocessable();
        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_plan_id' => null, 'pricing_factor' => 11], $headers)->assertJsonValidationErrors(['pricing_factor']);
        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_factor' => 1], $headers)->assertJsonValidationErrors(['pricing_plan_id']);

        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_plan_id' => null, 'pricing_factor' => '0.75'], $headers)
            ->assertOk()
            ->assertJsonPath('data.uses_default_plan', true)
            ->assertJsonPath('data.plan.id', PricingPlan::default()->id)
            ->assertJsonPath('data.pricing_factor', '0.75');
    }

    public function test_billing_list_detail_and_csv_use_aggregated_usage(): void
    {
        $headers = $this->superadminHeaders();
        $org = $this->makeOrganization('Pines');
        $planId = $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['base_price' => '10.00']), $headers)->json('data.id');
        $this->putJson("/api/v1/superadmin/billing/organizations/{$org->id}/plan", ['pricing_plan_id' => $planId, 'pricing_factor' => '0.5'], $headers)->assertOk();

        $month = now('UTC')->format('Y-m');
        $metrics = array_fill_keys(UsageMetrics::keys(), 0);
        $metrics['bookings'] = 300;
        OrganizationUsageSnapshot::create([
            'engage_organization_location_id' => $org->id, 'snapshot_date' => now('UTC')->toDateString(),
            'metrics' => $metrics, 'metric_status' => array_fill_keys(UsageMetrics::keys(), 'ok'),
            'status' => 'complete', 'measured_at' => now(),
        ]);

        $row = collect($this->getJson("/api/v1/superadmin/billing/organizations?month={$month}&search=pines", $headers)->assertOk()->json('data.organizations'))
            ->firstWhere('organization.id', $org->id);
        $this->assertSame('complete', $row['measurement']['status']);
        $this->assertSame('20.00', $row['bill']['subtotal']); // 10 + (300-100)/100×5
        $this->assertSame('10.00', $row['bill']['total']);

        $this->getJson("/api/v1/superadmin/billing/organizations/{$org->id}?month={$month}", $headers)
            ->assertOk()->assertJsonCount(1, 'data.daily_snapshots')->assertJsonPath('data.bill.total', '10.00');

        $this->getJson('/api/v1/superadmin/billing/organizations?month=2026-13', $headers)->assertJsonValidationErrors(['month']);
        $this->getJson('/api/v1/superadmin/billing/organizations?month='.now('UTC')->addMonth()->format('Y-m'), $headers)->assertUnprocessable();

        $csv = $this->get("/api/v1/superadmin/billing/export?month={$month}&search=pines", $headers);
        $csv->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString("usage-billing-{$month}.csv", $csv->headers->get('Content-Disposition'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv->getContent())));
        $header = $lines[0];
        $data = array_combine($header, $lines[1]);
        $this->assertSame($org->name, $data['Organization']);
        $this->assertSame('300', $data['Bookings usage (bookings)']);
        $this->assertSame('10.00', $data['Estimated total']);
    }

    public function test_csv_neutralizes_formula_like_organization_names(): void
    {
        $headers = $this->superadminHeaders();
        $org = $this->makeOrganization('=HYPERLINK');

        $csv = $this->get('/api/v1/superadmin/billing/export?search=hyperlink', $headers)->getContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertNotNull($org);
    }

    public function test_measure_now_runs_a_snapshot_and_returns_409_while_running(): void
    {
        $root = storage_path('framework/testing/usage-'.Str::random(8));
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.public.root' => $root, 'usage.storage.disks' => ['public']]);
        $headers = $this->superadminHeaders();
        $org = $this->makeOrganization();

        try {
            $this->postJson('/api/v1/superadmin/billing/measure', ['organization_id' => $org->id], $headers)
                ->assertOk()->assertJsonPath('data.organization_count', 1);
            $this->assertSame(1, OrganizationUsageSnapshot::where('engage_organization_location_id', $org->id)->count());

            $lock = Cache::lock(UsageSnapshotService::LOCK_KEY, 60);
            $lock->get();
            $this->postJson('/api/v1/superadmin/billing/measure', [], $headers)->assertStatus(409);
            $this->getJson('/api/v1/superadmin/billing/status', $headers)->assertOk()->assertJsonPath('data.running', true);
            $lock->release();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_pricing_plans_pdf_download(): void
    {
        $headers = $this->superadminHeaders() + ['Accept-Encoding' => 'gzip'];
        $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['name' => 'Pro <b>&</b> Co', 'base_price' => '99.00']), $headers)->assertCreated();

        $response = $this->get('/api/v1/superadmin/pricing-plans/pdf', $headers);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="pricing-plans-'.now('UTC')->format('Y-m-d').'.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('%%EOF', substr($response->getContent(), -64));
    }

    public function test_pdf_view_escapes_plan_text_and_lists_active_plans_by_price(): void
    {
        $headers = $this->superadminHeaders();
        $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['name' => 'Zeta <script>', 'base_price' => '5.00']), $headers);
        $this->postJson('/api/v1/superadmin/pricing-plans', $this->planPayload(['name' => 'Alpha Hidden', 'base_price' => '1.00', 'is_active' => false]), $headers);

        $data = app(PricingPlanPdfService::class)->viewData();
        $html = view('pdf.pricing-plans', $data)->render();

        $this->assertStringContainsString('Zeta &lt;script&gt;', $html);
        $this->assertStringNotContainsString('Alpha Hidden', $html);
        $prices = array_map(fn ($p) => (float) str_replace(',', '', $p['base_price']), $data['plans']);
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
        $this->assertNotNull($data['example']);
    }
}
