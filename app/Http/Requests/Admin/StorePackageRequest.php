<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Models\Group;
use App\Rules\NotRedactedCredentialUrl;
use App\Services\Registry\RegistryTypeService;
use App\Support\RepositoryUrlRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
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
        // Per-type name format + repository requirement both come from the PackageType
        // enum — the single source of truth — so a new type is defined in one place.
        $type = PackageType::tryFrom((string) $this->input('type'));
        $nameRegex = $type?->nameRegex() ?? '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/';

        // A repository is required whenever the package is git-sourced: always for
        // Composer, and for npm/Python when the chosen source mode is "git" (mirror).
        // The URL shape itself comes from RepositoryUrlRules, shared with the probe
        // endpoint the create mask gates saving on, so the two cannot disagree about
        // which URLs are acceptable — or about how they say so.
        $repositoryRequired = $this->effectiveSourceMode($type) === PackageSourceMode::Git;
        $repositoryRule = array_merge(
            [$repositoryRequired ? 'required' : 'nullable'],
            RepositoryUrlRules::shape(),
            [new NotRedactedCredentialUrl],
        );

        return [
            'type' => ['required', Rule::enum(PackageType::class)],
            // Which modes a type may use lives on the enum. npm is publish-only: a mirror
            // of the repository tree is not what `npm publish` uploads.
            'source_mode' => [
                'nullable',
                Rule::enum(PackageSourceMode::class),
                Rule::in(array_map(
                    fn (PackageSourceMode $m): string => $m->value,
                    PackageSourceMode::allowedFor($type ?? PackageType::Composer)
                )),
            ],
            'name' => [
                'required',
                'string',
                'max:190',
                "regex:{$nameRegex}",
                // Scoped to the owning organization: the name is unique within one
                // organization's namespace, not across the instance. A plain
                // ->where('organization_id', ...) would pass '' straight into the query
                // when the selection is absent or spans several organizations — and
                // organization_id is a uuid column, so Postgres rejects '' outright with
                // a QueryException instead of leaving the uniqueness rule to pass cleanly
                // (withValidator() below is what actually reports that case). The closure
                // matches nothing instead.
                Rule::unique('packages')
                    ->where('type', $this->input('type'))
                    ->where(function ($query) {
                        $owner = $this->ownerOrganizationId();

                        return $owner === ''
                            ? $query->whereRaw('1 = 0')
                            : $query->where('organization_id', $owner);
                    }),
            ],
            'repository_url' => $repositoryRule,
            // Optional access token for a private git repository (e.g. a GitHub PAT).
            // Only meaningful for git-synced types; ignored for publish-based ones.
            'repository_token' => ['nullable', 'string', 'max:500'],
            // Optionally reference a managed git credential instead of an inline token.
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
            // At least one registry is mandatory, and it is what resolves the owner:
            // ownerOrganizationId() reads the organization off the selected registries, and
            // that organization is both what `packages.organization_id` gets set to and what
            // the uniqueness rule above scopes against. With no registry there is no owner to
            // derive, which is precisely the ownerless row the enforcement migration refuses
            // to migrate. (Historically this rule also stopped a name being burned
            // instance-wide; `(organization_id, type, name)` makes that impossible now.)
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => ['uuid', 'exists:groups,id'],
        ];
    }

    /**
     * The source mode the package will be created with. A submitted mode is honoured only
     * if the type allows it — the rules() constraint rejects anything else before this
     * runs — and otherwise the type's default applies.
     */
    public function effectiveSourceMode(?PackageType $type): PackageSourceMode
    {
        if ($type === null) {
            return PackageSourceMode::Publish;
        }

        $submitted = PackageSourceMode::tryFrom((string) $this->input('source_mode'));

        return $submitted !== null && in_array($submitted, PackageSourceMode::allowedFor($type), true)
            ? $submitted
            : PackageSourceMode::defaultFor($type);
    }

    /**
     * The organization that will own the package: the one every selected registry belongs
     * to. Returns an empty string when the selection is absent or spans several, which
     * makes the uniqueness rule match nothing — withValidator() below is what reports it.
     */
    public function ownerOrganizationId(): string
    {
        $ids = Group::whereIn('id', (array) $this->input('group_ids', []))
            ->pluck('organization_id')->unique();

        return $ids->count() === 1 ? (string) $ids->first() : '';
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = PackageType::tryFrom((string) $this->input('type'));
            if ($type !== null && ! app(RegistryTypeService::class)->isGloballyEnabled($type)) {
                $validator->errors()->add('type', 'Dieser Registry-Typ ist instanzweit deaktiviert.');
            }

            if ($this->input('group_ids') !== null && $this->ownerOrganizationId() === '') {
                $validator->errors()->add('group_ids', 'Alle gewählten Registries müssen zur selben Organisation gehören.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            // Rule::in's default reads "The selected source mode is invalid." — English, in
            // a German UI. Reachable over the API only (the create dialog hides the field
            // for a type with a single mode), but it is still a user-visible string.
            'source_mode.in' => 'Dieser Quellmodus ist für den gewählten Pakettyp nicht zulässig.',
            'group_ids.required' => 'Bitte mindestens eine Registry auswählen.',
            'group_ids.min' => 'Bitte mindestens eine Registry auswählen.',
            // The repository-URL messages live with the rules they translate, so the probe
            // endpoint rejects the same URL with the same wording.
        ], RepositoryUrlRules::messages());
    }
}
