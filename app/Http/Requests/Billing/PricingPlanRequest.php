<?php

namespace App\Http\Requests\Billing;

use App\Support\Billing\UsageMetrics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and update (PUT) a pricing plan. On update every field is optional. */
class PricingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $plan = $this->route('pricingPlan');
        $required = $plan ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120', Rule::unique('pricing_plans', 'name')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency' => [$required, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'base_price' => [$required, 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'metrics' => ['sometimes', 'array'],
            'metrics.*.key' => ['required', 'string', 'distinct', Rule::in(UsageMetrics::keys())],
            'metrics.*.included_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'metrics.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,4'],
            'metrics.*.billing_unit' => ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,4'],
            'metrics.*.metric_factor' => ['required', 'numeric', 'min:'.UsageMetrics::FACTOR_MIN, 'max:'.UsageMetrics::FACTOR_MAX, 'decimal:0,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency.regex' => 'Currency must be a three-letter uppercase code, e.g. USD.',
            'metrics.*.billing_unit.gt' => 'Billing unit must be greater than zero.',
            'metrics.*.metric_factor.max' => 'Metric factor must be between '.UsageMetrics::FACTOR_MIN.' and '.UsageMetrics::FACTOR_MAX.'.',
            'metrics.*.metric_factor.min' => 'Metric factor must be between '.UsageMetrics::FACTOR_MIN.' and '.UsageMetrics::FACTOR_MAX.'.',
        ];
    }
}
