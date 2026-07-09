<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Organization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->input('role');

            if (! in_array($role, [UserRole::Admin->value, UserRole::Maintainer->value], true)) {
                return;
            }

            $organization = Organization::find($this->input('organization_id'));

            if ($organization !== null && ! $organization->is_operator) {
                $validator->errors()->add('role', 'Admin/Maintainer sind nur in der Betreiber-Organisation erlaubt.');
            }
        });
    }
}
