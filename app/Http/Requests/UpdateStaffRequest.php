<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageStaffUsers() ?? false;
    }

    public function rules(): array
    {
        $allowed = $this->user()?->assignableStaffRoles() ?? [];
        $staffId = $this->route('staff')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($staffId)],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::min(8)],
            'role' => ['sometimes', 'required', 'string', Rule::in($allowed ?: User::ASSIGNABLE_STAFF_ROLES)],
            'status' => ['sometimes', 'required', 'string', Rule::in(User::statuses())],
        ];
    }
}
