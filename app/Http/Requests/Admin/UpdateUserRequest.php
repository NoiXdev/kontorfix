<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
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
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
            'name' => ['sometimes', 'string', 'max:190'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->input('role');

            if (! in_array($role, [UserRole::Admin->value, UserRole::Maintainer->value], true)) {
                return;
            }

            $user = $this->route('user');

            if ($user instanceof User && ! (bool) $user->organization?->is_operator) {
                $validator->errors()->add('role', 'Admin/Maintainer sind nur in der Betreiber-Organisation erlaubt.');
            }
        });
    }
}
