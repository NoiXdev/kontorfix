<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpstreamRequest extends FormRequest
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
            'group_id' => ['required', 'uuid', 'exists:groups,id'],
            'type' => ['required', Rule::enum(PackageType::class)],
            'url' => ['required', 'string', 'max:500', 'url:https,http', 'starts_with:https://,http://'],
            'policy' => ['required', Rule::enum(UpstreamPolicy::class)],
            'auth_token' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'allowed_packages' => ['array'],
            'allowed_packages.*' => ['string', 'max:190'],
        ];
    }
}
