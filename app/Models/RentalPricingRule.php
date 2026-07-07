<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalPricingRule extends Model
{
    use HasUlids;

    protected $fillable = [
        'rental_id',
        'name',
        'applies_to',
        'base_price',
        'base_price_strategy',
        'rules',
        'security_deposit_amount',
        'security_deposit_refundable',
        'payment_terms',
        'priority',
        'ghl_pricing_rule_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'json',
            'payment_terms' => 'json',
            'security_deposit_refundable' => 'boolean',
            'base_price' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }
}
