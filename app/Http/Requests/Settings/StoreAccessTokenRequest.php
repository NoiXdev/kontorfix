<?php

namespace App\Http\Requests\Settings;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->organization_id !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'group_id' => [
                'nullable',
                'uuid',
                Rule::exists('groups', 'id')->whereIn('organization_id', $this->user()->accessibleOrganizationIds()),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
            // Optional lifetime. Omitting it keeps the token open-ended, which is what
            // every token issued before this existed is.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
