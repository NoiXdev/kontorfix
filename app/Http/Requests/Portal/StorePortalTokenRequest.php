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
                // Only groups of an organization the user belongs to — prevents
                // assignment to a foreign registry.
                //
                // Membership, deliberately, and NOT the organization the portal address
                // names. Narrowing this to the context organization would fold the two
                // refusals into one and answer both with a validation error, and they are
                // not the same refusal: a group of an organization the caller has no part in
                // is a bad field value, reportable on the field; a group of an organization
                // the caller genuinely belongs to, submitted under a different
                // organization's address, is an authorization question the field cannot
                // carry. TokenController::store() answers that second case with a 403.
                Rule::exists('groups', 'id')->whereIn('organization_id', $this->user()->accessibleOrganizationIds()),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
            // Optional lifetime. Omitting it keeps the token open-ended.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
