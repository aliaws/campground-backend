<?php

namespace App\Models;

use App\Support\Billing\UsageMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A usage-based pricing plan (superadmin only). Exactly one plan is the
 * default; organizations without an assigned plan are billed on it.
 * Rules (default can't be deleted/deactivated, plans in use can't be
 * deleted) live in App\Services\Billing\PricingPlanService.
 */
class PricingPlan extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'description', 'currency', 'base_price', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PricingPlanMetric::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(EngageOrganizationLocation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    /**
     * The plan's configuration for every registered metric: its stored row
     * where there is one, the registry's safe default (nothing included,
     * free) otherwise. Values are decimal strings.
     *
     * @return array<string, array{included_quantity: string, unit_price: string, billing_unit: string, metric_factor: string, configured: bool}>
     */
    public function metricConfigs(): array
    {
        $rows = $this->relationLoaded('metrics') ? $this->metrics : $this->metrics()->get();
        $byKey = $rows->keyBy('metric_key');

        $configs = [];
        foreach (UsageMetrics::keys() as $key) {
            $row = $byKey->get($key);
            $configs[$key] = $row
                ? [
                    'included_quantity' => (string) $row->included_quantity,
                    'unit_price' => (string) $row->unit_price,
                    'billing_unit' => (string) $row->billing_unit,
                    'metric_factor' => (string) $row->metric_factor,
                    'configured' => true,
                ]
                : UsageMetrics::defaultConfig($key) + ['configured' => false];
        }

        return $configs;
    }
}
