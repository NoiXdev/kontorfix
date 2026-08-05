<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        return [
            // `sometimes` keeps partial updates working (e.g. a role-only change from a
            // dropdown) while the full edit form still validates name/email/home org.
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'organization_id' => ['sometimes', 'required', 'uuid', 'exists:organizations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->input('role');

            if (! in_array($role, [UserRole::Admin->value, UserRole::Maintainer->value], true)) {
                return;
            }

            // Admin/Maintainer only make sense inside the operator organization. Check
            // against the org the edit assigns the user to, or — for a role-only update
            // that doesn't touch the home org — against the user's current org.
            $user = $this->route('user');
            $targetOrgId = $this->input('organization_id')
                ?? ($user instanceof User ? $user->organization_id : null);
            $organization = $targetOrgId !== null ? Organization::find($targetOrgId) : null;

            if ($organization !== null && ! $organization->is_operator) {
                $validator->errors()->add('role', 'Admin/Maintainer sind nur in der Betreiber-Organisation erlaubt.');
            }
        });
    }
}
