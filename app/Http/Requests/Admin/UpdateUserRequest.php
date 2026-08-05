<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Only normalise the super-admin flag when the form actually submits it, so a
        // role-only dropdown change doesn't silently clear it.
        if ($this->has('is_super_admin')) {
            $this->merge(['is_super_admin' => $this->boolean('is_super_admin')]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        // Roles are per-organization — no operator-org restriction. Global reach is the
        // separate super-admin flag.
        return [
            // `sometimes` keeps partial updates working (e.g. a role-only change from a
            // dropdown) while the full edit form still validates name/email/home org.
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_super_admin' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'required', 'uuid', 'exists:organizations,id'],
        ];
    }
}
