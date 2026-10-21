<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One metric's allowance and price inside a pricing plan. */
class PricingPlanMetric extends Model
{
    use HasUlids;

    protected $fillable = ['pricing_plan_id', 'metric_key', 'included_quantity', 'unit_price', 'billing_unit', 'metric_factor'];

    protected function casts(): array
    {
        return [
            'included_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'billing_unit' => 'decimal:4',
            'metric_factor' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }
}
