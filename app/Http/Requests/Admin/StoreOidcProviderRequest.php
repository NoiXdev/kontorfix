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
            // https, not just `url`. UrlSafety's scheme allowlist permits http on purpose —
            // it is built for artifact URLs, where an internal mirror without TLS is a real
            // deployment — but an identity provider is different: OidcService POSTs the
            // client_secret to the token endpoint and fetches the JWKS that every id_token
            // signature is checked against. Over http that hands an on-path attacker the
            // secret in cleartext and lets them serve a forged key set. OIDC Core requires
            // https of the issuer anyway, so this refuses nothing the spec allows.
            'issuer' => ['required', 'url:https'],
            'authorization_endpoint' => ['required', 'url:https'],
            'token_endpoint' => ['required', 'url:https'],
            'jwks_uri' => ['required', 'url:https'],
            'userinfo_endpoint' => ['nullable', 'url:https'],
            'scopes' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
            'allow_registration' => ['boolean'],
            'trusts_email_claim' => ['boolean'],
            'default_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'default_role' => ['nullable', Rule::enum(UserRole::class)],
        ];
    }
}
