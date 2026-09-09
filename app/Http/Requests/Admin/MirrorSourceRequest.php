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
                // Same SSRF net as the outgoing webhook URL: an admin-supplied mirror
                // address must not be able to reach into the deployment's private network.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! UrlSafety::isSafe($value)) {
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
