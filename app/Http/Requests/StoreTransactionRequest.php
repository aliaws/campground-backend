<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'max:26', 'exists:customers,id'],
            'booking_id' => ['nullable', 'string', 'max:26', 'exists:bookings,id'],
            'payment_method' => ['required', Rule::in(['cash', 'card'])],
            'payment_status' => ['required', Rule::in(['paid', 'pending', 'draft'])],
            'tenant_id' => ['required', 'string', 'max:26'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'max:26', 'exists:products,id'],
            'items.*.product_type' => ['required', Rule::in(['rental', 'physical', 'addon'])],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.rental_start' => ['nullable', 'date'],
            'items.*.rental_end' => ['nullable', 'date', 'after_or_equal:items.*.rental_start'],
        ];
    }
}
