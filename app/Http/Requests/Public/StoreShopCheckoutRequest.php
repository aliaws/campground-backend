<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Unlike the booking flow (email always required), Shop checkout
            // only needs one contact channel — user-directed: "make sure on
            // checkout email or phone is compulsory."
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'max:26'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
