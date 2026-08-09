<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Rules\UniqueEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalised, because `OidcUserResolver` already treats an address as one identity
        // whatever its case. Storing what was typed while matching on `lower(email)` is what
        // let `Root@firma.de` and `root@firma.de` both exist.
        $this->merge([
            'is_super_admin' => $this->boolean('is_super_admin'),
            ...$this->has('email') && is_string($this->input('email'))
                ? ['email' => Str::lower(trim($this->input('email')))]
                : [],
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
            'email' => ['required', 'email', 'max:190', new UniqueEmail],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_super_admin' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8'],
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
        ];
    }
}
