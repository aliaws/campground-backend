<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Http\FormRequest;

/** Assign a plan (null = default plan) and a pricing factor to an organization. */
class AssignPricingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pricing_plan_id' => ['present', 'nullable', 'string', 'exists:pricing_plans,id'],
            'pricing_factor' => ['required', 'numeric', 'min:'.UsageMetrics::FACTOR_MIN, 'max:'.UsageMetrics::FACTOR_MAX, 'decimal:0,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'pricing_factor.min' => 'Pricing factor must be between '.UsageMetrics::FACTOR_MIN.' and '.UsageMetrics::FACTOR_MAX.' (1 = list price, 0.9 = 10% discount).',
            'pricing_factor.max' => 'Pricing factor must be between '.UsageMetrics::FACTOR_MIN.' and '.UsageMetrics::FACTOR_MAX.' (1 = list price, 0.9 = 10% discount).',
        ];
    }
}
