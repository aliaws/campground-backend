<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // campsite = engage_rental_transactions id, product = engage_product_transactions id
            'type' => ['required', 'in:campsite,product'],
            'transaction_id' => ['required', 'string', 'max:26'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'cancel_booking' => ['sometimes', 'boolean'],
        ];
    }
}
