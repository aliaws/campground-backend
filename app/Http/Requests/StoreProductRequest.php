<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', Rule::in(['DIGITAL', 'PHYSICAL', 'SERVICE'])],
            'parent_product_id' => ['nullable', 'string', 'max:26', 'exists:products,id'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'ghl_service_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'is_variable' => ['nullable', 'boolean'],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'thumbnail' => ['nullable', 'string', 'max:2048'],
            'medias' => ['nullable', 'array'],
            'display_priority' => ['nullable', 'integer', 'min:0'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            // SERVICE-type (campsite) fields
            'site_type' => ['nullable', Rule::in(['tent', 'rv', 'cabin', 'glamping', 'group'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'available_quantity' => ['nullable', 'integer', 'min:0'],
            'hookups' => ['nullable', 'array'],
            'hookups.*' => [Rule::in(['electric_30amp', 'electric_50amp', 'water', 'sewer', 'none'])],
            'map_position' => ['nullable', 'array'],
            'map_position.lat' => ['required_with:map_position', 'numeric'],
            'map_position.lng' => ['required_with:map_position', 'numeric'],
            'map_polygon' => ['nullable', 'array'],
            'map_polygon.*.lat' => ['required', 'numeric'],
            'map_polygon.*.lng' => ['required', 'numeric'],
            'pet_friendly' => ['nullable', 'boolean'],
            'ada_accessible' => ['nullable', 'boolean'],
            'campsite_status' => ['nullable', Rule::in(['available', 'occupied', 'maintenance', 'reserved'])],
            // Booking configuration
            'booking_unit' => ['nullable', Rule::in(['day'])],
            'min_duration' => ['nullable', 'integer', 'min:1'],
            'max_duration' => ['nullable', 'integer', 'min:1', 'gte:min_duration'],
            'duration_unit' => ['nullable', Rule::in(['day'])],
            'booking_start_time' => ['nullable', 'date_format:H:i'],
            'booking_end_time' => ['nullable', 'date_format:H:i'],
            'max_quantity' => ['nullable', 'integer', 'min:1'],
            // Pricing rule (booking price engine — GHL pricingRule shape)
            ...$this->pricingRuleRules(),
            // Relations
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:categories,id'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],
            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['string', 'exists:features,id'],
            // Variants
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.position' => ['nullable', 'integer', 'min:0'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.options.*.id' => ['nullable', 'string'],
            'variants.*.options.*.name' => ['required_with:variants.*.options', 'string', 'max:100'],
            'variants.*.options.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Validation for the nested pricing_rule JSON object (GHL pricingRule shape). */
    public static function pricingRuleRules(): array
    {
        return [
            'pricing_rule' => ['nullable', 'array'],
            'pricing_rule.name' => ['nullable', 'string', 'max:255'],
            'pricing_rule.applies_to' => ['nullable', Rule::in(['rental', 'service'])],
            'pricing_rule.base_price' => ['required_with:pricing_rule', 'numeric', 'min:0'],
            'pricing_rule.base_price_strategy' => ['nullable', Rule::in(['per_day', 'per_booking'])],
            'pricing_rule.rules' => ['nullable', 'array'],
            'pricing_rule.rules.*.type' => ['required', Rule::in(Product::RULE_TYPES)],
            'pricing_rule.rules.*.value' => ['required', 'numeric'],
            'pricing_rule.rules.*.valueType' => ['required', Rule::in(Product::VALUE_TYPES)],
            'pricing_rule.rules.*.sequence' => ['nullable', 'integer', 'min:0'],
            'pricing_rule.rules.*.match' => ['required', 'array'],
            'pricing_rule.rules.*.match.from' => ['required_if:pricing_rule.rules.*.type,date_range', 'date'],
            'pricing_rule.rules.*.match.to' => ['required_if:pricing_rule.rules.*.type,date_range', 'date', 'after_or_equal:pricing_rule.rules.*.match.from'],
            'pricing_rule.rules.*.match.dayOfWeek' => ['required_if:pricing_rule.rules.*.type,day_of_week', 'integer', 'between:0,6'],
            'pricing_rule.rules.*.match.duration' => ['required_if:pricing_rule.rules.*.type,duration_discount', 'integer', 'min:1'],
            'pricing_rule.rules.*.match.durationUnit' => ['nullable', Rule::in(['day'])],
            'pricing_rule.rules.*.match.min' => ['required_if:pricing_rule.rules.*.type,quantity_discount', 'integer', 'min:1'],
            'pricing_rule.security_deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'pricing_rule.security_deposit_refundable' => ['nullable', 'boolean'],
            'pricing_rule.payment_terms' => ['nullable', 'array'],
            'pricing_rule.payment_terms.type' => ['required_with:pricing_rule.payment_terms', Rule::in(['full', 'partial'])],
            'pricing_rule.ghl_pricing_rule_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
