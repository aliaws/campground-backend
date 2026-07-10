<?php

namespace App\Http\Requests;

use App\Rules\BookableRentalId;
use Illuminate\Foundation\Http\FormRequest;

class QuoteBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'max:26', new BookableRentalId],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
