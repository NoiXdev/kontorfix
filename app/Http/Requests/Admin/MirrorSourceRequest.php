<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
use App\Services\Upstream\UrlSafety;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store and update share one request: the fields and rules are identical for both — the
 * token is optional either way (a mirror source needs no auth for a public registry), and a
 * blank value on update simply means "keep the currently stored token" (see
 * MirrorSourceController::update()), so there is no separate "required on create" rule to
 * diverge from "nullable on update" the way GitCredentialController's token is.
 */
class MirrorSourceRequest extends FormRequest
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
            // Absent/null lets MirrorSourceController::resolveCreationOrg() fall back to the
            // active console scope, then the caller's home organization — the same
            // GitCredentialController pattern. Without a rule here the create form's org
            // picker had nothing to submit into: the key never reached validated() at all,
            // so an explicit selection was silently ignored.
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:190'],
            // Docker is deliberately excluded: a Docker "mirror" is a registry-to-registry
            // pull-through, a different mechanism entirely from the Composer/npm/PyPI HTTP
            // mirrors this feature serves — see the brief for Task 7.
            'type' => ['required', Rule::in([
                PackageType::Composer->value,
                PackageType::Npm->value,
                PackageType::Python->value,
            ])],
            'url' => [
                'required', 'string', 'max:500', 'url',
                // DNS-resolving, not just isSafe()'s IP-literal check: a mirror source is
                // configured once and then fetched from repeatedly by a background job
                // (SyncMirrorPackage), unlike a one-shot outgoing webhook — so a hostname
                // that resolves internally (vault.internal, or an octal/decimal-encoded
                // private IP isSafe() alone would not decode) must be refused at
                // configuration time, not only ever re-checked per-request deep inside
                // UpstreamClient. Fails closed on a hostname that will not resolve at all.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! UrlSafety::isSafeResolving($value)) {
                        $fail('Registry-URL nicht erlaubt (interne/reservierte Adresse).');
                    }
                },
            ],
            // Blank keeps the stored token on update; absent/blank on create simply means no
            // auth is configured — some mirrored registries are public.
            'auth_token' => ['nullable', 'string', 'max:500'],
        ];
    }
}
