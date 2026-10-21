<?php

namespace App\Services\Billing;

use App\Models\EngageOrganizationLocation;
use App\Models\PricingPlan;
use App\Support\Billing\UsageMetrics;
use Illuminate\Support\Facades\DB;

/**
 * Pricing plan changes, always inside a database transaction. Rules:
 * - exactly one default plan; making a plan the default clears the old one
 *   in the same transaction (rows locked) and activates the new default;
 * - the default plan can't be deactivated or deleted;
 * - a plan assigned to any organization can't be deleted;
 * - an inactive plan can't be newly assigned (existing assignments stay).
 */
class PricingPlanService
{
    public function create(array $data): PricingPlan
    {
        return DB::transaction(function () use ($data) {
            $plan = PricingPlan::create($this->planAttributes($data));
            $this->syncMetrics($plan, $data['metrics'] ?? []);

            if (! empty($data['is_default'])) {
                $this->makeDefaultInTransaction($plan);
            }

            return $plan->fresh('metrics');
        });
    }

    public function update(PricingPlan $plan, array $data): PricingPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan = PricingPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $plan->is_default) {
                throw new PricingPlanRuleException('The default plan cannot be deactivated. Make another plan the default first.');
            }

            $plan->update($this->planAttributes($data, $plan));
            if (array_key_exists('metrics', $data)) {
                $this->syncMetrics($plan, $data['metrics'] ?? []);
            }

            if (! empty($data['is_default']) && ! $plan->is_default) {
                $this->makeDefaultInTransaction($plan);
            }

            return $plan->fresh('metrics');
        });
    }

    public function setActive(PricingPlan $plan, bool $active): PricingPlan
    {
        return DB::transaction(function () use ($plan, $active) {
            $plan = PricingPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if (! $active && $plan->is_default) {
                throw new PricingPlanRuleException('The default plan cannot be deactivated. Make another plan the default first.');
            }
            $plan->forceFill(['is_active' => $active])->save();

            return $plan->fresh('metrics');
        });
    }

    public function makeDefault(PricingPlan $plan): PricingPlan
    {
        return DB::transaction(function () use ($plan) {
            $plan = PricingPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->makeDefaultInTransaction($plan);

            return $plan->fresh('metrics');
        });
    }

    public function delete(PricingPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $plan = PricingPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->is_default) {
                throw new PricingPlanRuleException('The default plan cannot be deleted.');
            }
            $assigned = EngageOrganizationLocation::query()->where('pricing_plan_id', $plan->id)->count();
            if ($assigned > 0) {
                throw new PricingPlanRuleException("This plan is assigned to {$assigned} organization(s). Assign them another plan before deleting it.");
            }
            $plan->delete();
        });
    }

    /** Assigns a plan (null = use the default plan) and a pricing factor to an organization. */
    public function assign(EngageOrganizationLocation $organization, ?string $planId, string $pricingFactor): EngageOrganizationLocation
    {
        return DB::transaction(function () use ($organization, $planId, $pricingFactor) {
            if ($planId !== null && $planId !== $organization->pricing_plan_id) {
                $plan = PricingPlan::query()->lockForUpdate()->findOrFail($planId);
                if (! $plan->is_active) {
                    throw new PricingPlanRuleException('An inactive plan cannot be assigned.');
                }
            }

            $organization->forceFill([
                'pricing_plan_id' => $planId,
                'pricing_factor' => $pricingFactor,
            ])->save();

            return $organization->fresh('pricingPlan');
        });
    }

    private function makeDefaultInTransaction(PricingPlan $plan): void
    {
        // Lock every plan row so two concurrent "make default" requests serialize.
        PricingPlan::query()->lockForUpdate()->get(['id']);
        PricingPlan::query()->where('is_default', true)->where('id', '!=', $plan->id)->update(['is_default' => false]);
        $plan->forceFill(['is_default' => true, 'is_active' => true])->save();
    }

    private function planAttributes(array $data, ?PricingPlan $existing = null): array
    {
        $attributes = [];
        foreach (['name', 'description', 'base_price', 'is_active', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }
        if (array_key_exists('currency', $data)) {
            $attributes['currency'] = strtoupper((string) $data['currency']);
        }
        if ($existing === null) {
            $attributes += ['currency' => 'USD', 'base_price' => 0, 'is_active' => true, 'sort_order' => 0];
        }

        return $attributes;
    }

    /**
     * Stores the given metric rows (keys must be registered); registered
     * metrics not sent keep their existing row, or use safe defaults.
     *
     * @param  list<array{key: string, included_quantity?: mixed, unit_price?: mixed, billing_unit?: mixed, metric_factor?: mixed}>  $metrics
     */
    private function syncMetrics(PricingPlan $plan, array $metrics): void
    {
        foreach ($metrics as $metric) {
            $key = $metric['key'];
            if (! UsageMetrics::exists($key)) {
                continue;
            }
            $defaults = UsageMetrics::defaultConfig($key);
            $plan->metrics()->updateOrCreate(['metric_key' => $key], [
                'included_quantity' => $metric['included_quantity'] ?? $defaults['included_quantity'],
                'unit_price' => $metric['unit_price'] ?? $defaults['unit_price'],
                'billing_unit' => $metric['billing_unit'] ?? $defaults['billing_unit'],
                'metric_factor' => $metric['metric_factor'] ?? $defaults['metric_factor'],
            ]);
        }
    }
}
