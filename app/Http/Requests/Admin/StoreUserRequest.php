<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_super_admin' => $this->boolean('is_super_admin'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Roles are per-organization now — admin/maintainer/member is valid in any
        // organization, so there is no longer an operator-org restriction. Global reach
        // is granted separately via the super-admin flag.
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_super_admin' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8'],
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
        ];
    }
}
