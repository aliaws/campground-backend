<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageStaffUsers() ?? false;
    }

    public function rules(): array
    {
        $allowed = $this->user()?->assignableStaffRoles() ?? [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:engage_users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['required', 'string', Rule::in($allowed ?: User::ASSIGNABLE_STAFF_ROLES)],
        ];
    }
}
