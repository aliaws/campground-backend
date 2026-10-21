<?php

namespace App\Services\Billing;

use App\Models\EngageOrganizationLocation;
use App\Models\OrganizationUsageSnapshot;
use App\Models\PricingPlan;
use App\Support\Billing\UsageMetrics;
use Illuminate\Support\Collection;

/**
 * Builds the superadmin's monthly usage and estimated-bill view: each
 * organization's aggregated usage (MonthlyUsageAggregator) priced on its
 * effective plan (assigned plan, else the default) with its pricing factor
 * (BillingCalculator). Estimates only — nothing is charged.
 */
class UsageBillingService
{
    public function __construct(
        private readonly MonthlyUsageAggregator $aggregator,
        private readonly BillingCalculator $calculator,
    ) {}

    /** @return list<array> one row per organization, sorted by name */
    public function organizationRows(string $month, ?string $search = null): array
    {
        $organizations = EngageOrganizationLocation::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q
                    ->whereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(business_email, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(engage_location_id, \'\')) LIKE ?', [$term]));
            })
            ->orderByRaw('LOWER(COALESCE(name, \'\'))')
            ->get();

        $plans = $this->plans();
        $usage = $this->aggregator->forOrganizations($organizations->pluck('id')->all(), $month);

        return $organizations->map(fn (EngageOrganizationLocation $org) => $this->row($org, $usage[$org->id], $plans))->all();
    }

    public function organizationDetail(EngageOrganizationLocation $organization, string $month): array
    {
        $plans = $this->plans();
        $usage = $this->aggregator->forOrganization($organization->id, $month);
        [$start, $end] = MonthlyUsageAggregator::monthRange($month);

        $daily = OrganizationUsageSnapshot::query()
            ->where('engage_organization_location_id', $organization->id)
            ->whereBetween('snapshot_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn (OrganizationUsageSnapshot $s) => [
                'date' => $s->snapshot_date->toDateString(),
                'status' => $s->status,
                'metrics' => $s->metrics,
                'metric_status' => $s->metric_status,
                'measured_at' => $s->measured_at?->toIso8601String(),
            ])->all();

        return $this->row($organization, $usage, $plans) + [
            'usage' => $usage,
            'daily_snapshots' => $daily,
        ];
    }

    /** @return Collection<string, PricingPlan> keyed by id, with metrics loaded */
    private function plans(): Collection
    {
        return PricingPlan::query()->with('metrics')->get()->keyBy('id');
    }

    private function row(EngageOrganizationLocation $org, array $usage, Collection $plans): array
    {
        $assigned = $org->pricing_plan_id ? $plans->get($org->pricing_plan_id) : null;
        $plan = $assigned ?? $plans->firstWhere('is_default', true);
        $factor = (string) ($org->pricing_factor ?? '1');

        $quantities = [];
        foreach (UsageMetrics::keys() as $key) {
            $quantities[$key] = $usage['metrics'][$key]['quantity'] ?? null;
        }

        $bill = $plan ? $this->calculator->calculate($plan, $quantities, $factor) : null;
        if ($bill) {
            foreach ($bill['lines'] as $i => $line) {
                $bill['lines'][$i]['usage_status'] = $usage['metrics'][$line['key']]['status'];
                $bill['lines'][$i]['aggregation'] = $usage['metrics'][$line['key']]['aggregation'];
            }
        }

        return [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'status' => $org->status,
                'business_email' => $org->business_email,
                'engage_location_id' => $org->engage_location_id,
            ],
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'currency' => $plan->currency,
                'is_active' => $plan->is_active,
                'is_default' => $plan->is_default,
            ] : null,
            'assigned_plan_id' => $org->pricing_plan_id,
            'uses_default_plan' => $assigned === null,
            'pricing_factor' => rtrim(rtrim($factor, '0'), '.') ?: '0',
            'month' => $usage['month'],
            'measurement' => [
                'status' => $usage['status'],
                'flags' => $usage['flags'],
                'source' => $usage['source'],
                'snapshot_days' => $usage['snapshot_days'],
                'latest_snapshot_date' => $usage['latest_snapshot_date'],
                'latest_measured_at' => $usage['latest_measured_at'],
            ],
            'quantities' => $quantities,
            'bill' => $bill,
        ];
    }
}
