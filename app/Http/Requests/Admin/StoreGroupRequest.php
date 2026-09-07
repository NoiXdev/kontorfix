<?php

namespace App\Http\Requests\Admin;

use App\Rules\AddressableSlug;
use App\Rules\UnclaimedSlug;
use App\Services\Scope\OrgScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'public' => $this->boolean('public'),
            // Default a newly created group to portal-visible unless explicitly disabled.
            'portal_enabled' => $this->has('portal_enabled') ? $this->boolean('portal_enabled') : true,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            // Scoped to the organization the registry is created in: a slug is unique
            // within one organization's namespace, not across the instance. A plain
            // ->where('organization_id', ...) would pass '' straight into the query when
            // no organization can be resolved — and organization_id is a uuid column, so
            // Postgres rejects '' outright with a QueryException instead of leaving the
            // rule to pass cleanly. The closure matches nothing instead; the controller's
            // resolveCreationOrg() is what refuses that request, with a 403.
            'slug' => [
                'required',
                'string',
                'max:190',
                // Narrower than routes/registry.php's `$ociName` on purpose — a registry slug
                // is a path component of an OCI repository name under path addressing, and
                // the OCI grammar admits a hyphen only between alphanumerics. See
                // App\Rules\AddressableSlug.
                new AddressableSlug,
                Rule::unique('groups', 'slug')->where(function ($query) {
                    $owner = $this->ownerOrganizationId();

                    return $owner === ''
                        ? $query->whereRaw('1 = 0')
                        : $query->where('organization_id', $owner);
                }),
                // …and never equal to an organization slug, in any organization: the two
                // share one namespace in the registry URL. See App\Rules\UnclaimedSlug.
                UnclaimedSlug::byOrganization(),
            ],
            'public' => ['boolean'],
            'portal_enabled' => ['boolean'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'package_ids' => ['array'],
            // Existence only — ownership cannot be expressed as a rule here because this
            // request serves both the console (sidebar scope) and the API (the caller's
            // administered orgs). The controllers enforce it via assertCanAttachPackages().
            'package_ids.*' => ['uuid', 'exists:packages,id'],
        ];
    }

    /**
     * The organization the registry will be created in — the same value the controller
     * resolves through resolveCreationOrg(): an explicit choice first, then the active
     * console scope, then the user's own home organization. Returns an empty string when
     * none of them yields one, which makes the uniqueness rule above match nothing; the
     * controller then refuses the request outright (assertAdministersOrg).
     *
     * Deliberately not validated for administerability here — that decision stays in one
     * place, the controller — so this value only ever narrows a uniqueness check.
     */
    public function ownerOrganizationId(): string
    {
        $requested = $this->input('organization_id');
        $requested = is_string($requested) ? $requested : '';

        // Same precedence as ScopesToAdministeredOrgs::resolveCreationOrg(), which is what
        // actually decides — this only has to agree with it, or the rule would scope the
        // check to a different organization than the one the row lands in.
        return (string) ($requested ?: (app(OrgScope::class)->creationOrganizationId() ?: Auth::user()?->organization_id));
    }
}
