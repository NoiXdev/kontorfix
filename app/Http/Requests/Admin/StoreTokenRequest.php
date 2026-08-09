<?php

namespace App\Http\Requests\Admin;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenRequest extends FormRequest
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
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'group_id' => [
                'nullable',
                'uuid',
                Rule::exists('groups', 'id')->where('organization_id', $this->input('organization_id')),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
            // Optional lifetime. Omitting it keeps the token open-ended.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
