<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUpstreamRequest extends FormRequest
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
            'type' => ['required', Rule::enum(PackageType::class)],
            'url' => ['required', 'string', 'max:500', 'url:https,http', 'starts_with:https://,http://'],
            'policy' => ['required', Rule::enum(UpstreamPolicy::class)],
            // Blank keeps the stored token; a value replaces it. `remove_auth_token`
            // explicitly clears it (a private upstream becoming public).
            'auth_token' => ['nullable', 'string', 'max:500'],
            'remove_auth_token' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'enabled' => ['sometimes', 'boolean'],
            'allowed_packages' => ['array'],
            'allowed_packages.*' => ['string', 'max:190'],
        ];
    }
}
