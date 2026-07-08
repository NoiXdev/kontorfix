<?php

namespace App\Http\Requests\Portal;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortalTokenRequest extends FormRequest
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
            'group_id' => [
                'nullable',
                'uuid',
                // Nur Gruppen der eigenen Org zulassen — verhindert Zuweisung an fremde Registry.
                Rule::exists('groups', 'id')->where('organization_id', $this->user()->organization_id),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
        ];
    }
}
