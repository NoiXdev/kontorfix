<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOidcProviderRequest extends FormRequest
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
            'slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/', 'unique:oidc_providers,slug'],
            'client_id' => ['required', 'string', 'max:190'],
            'client_secret' => ['required', 'string', 'max:500'],
            'issuer' => ['required', 'url'],
            'authorization_endpoint' => ['required', 'url'],
            'token_endpoint' => ['required', 'url'],
            'jwks_uri' => ['required', 'url'],
            'userinfo_endpoint' => ['nullable', 'url'],
            'scopes' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
            'allow_registration' => ['boolean'],
            'default_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'default_role' => ['nullable', Rule::enum(UserRole::class)],
        ];
    }
}
