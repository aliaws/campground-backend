<?php

namespace App\Http\Resources;

use App\Support\Billing\UsageMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A pricing plan with one entry per registered metric (stored row or safe default). */
class PricingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $configs = $this->metricConfigs();
        $metrics = [];
        foreach (UsageMetrics::keys() as $key) {
            $metrics[] = ['key' => $key] + array_map(
                fn ($value) => is_string($value) && str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value,
                $configs[$key]
            );
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => $this->currency,
            'base_price' => (string) $this->base_price,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
            'organizations_count' => $this->whenCounted('organizations'),
            'metrics' => $metrics,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
