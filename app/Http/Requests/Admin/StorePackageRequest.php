<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Rules\NotRedactedCredentialUrl;
use App\Services\Registry\RegistryTypeService;
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
        // Only real Git remotes over https/ssh — no file:// or gopher:// etc., which
        // would otherwise be passed to the git subprocess as an SSRF surface.
        $repositoryRequired = $this->effectiveSourceMode($type) === PackageSourceMode::Git;
        $repositoryRule = array_merge(
            [$repositoryRequired ? 'required' : 'nullable'],
            ['string', 'max:500', new NotRedactedCredentialUrl, 'url:https,ssh', 'starts_with:https://,ssh://'],
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
                Rule::unique('packages')->where('type', $this->input('type')),
            ],
            'repository_url' => $repositoryRule,
            // Optional access token for a private git repository (e.g. a GitHub PAT).
            // Only meaningful for git-synced types; ignored for publish-based ones.
            'repository_token' => ['nullable', 'string', 'max:500'],
            // Optionally reference a managed git credential instead of an inline token.
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
            // At least one registry is mandatory. `packages.name` is unique per type across
            // the whole instance, but a package belongs to an organization only through the
            // registries it is attached to — so a package created with none burns the name
            // instance-wide while being invisible to its own creator (every package listing
            // joins through `groups`) and, since orphans are attachable only by a
            // super-admin, unrecoverable for the creating organization. The attach gate in
            // GuardsPackageAttachment remains the primary control against *taking* a
            // package; this stops one being created into limbo in the first place.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = PackageType::tryFrom((string) $this->input('type'));
            if ($type !== null && ! app(RegistryTypeService::class)->isGloballyEnabled($type)) {
                $validator->errors()->add('type', 'Dieser Registry-Typ ist instanzweit deaktiviert.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Rule::in's default reads "The selected source mode is invalid." — English, in
            // a German UI. Reachable over the API only (the create dialog hides the field
            // for a type with a single mode), but it is still a user-visible string.
            'source_mode.in' => 'Dieser Quellmodus ist für den gewählten Pakettyp nicht zulässig.',
            'repository_url.starts_with' => 'Die Repository-URL muss mit https:// oder ssh:// beginnen.',
            'repository_url.url' => 'Bitte eine gültige https- oder ssh-Repository-URL angeben.',
            'group_ids.required' => 'Bitte mindestens eine Registry auswählen.',
            'group_ids.min' => 'Bitte mindestens eine Registry auswählen.',
        ];
    }
}
