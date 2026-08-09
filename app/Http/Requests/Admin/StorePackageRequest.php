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
            // Composer is always git; npm/Python default to publish but may mirror git.
            'source_mode' => ['nullable', Rule::enum(PackageSourceMode::class)],
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
     * The source mode the package will be created with: Composer is always Git; npm/Python
     * honour the submitted mode, defaulting to Publish.
     */
    public function effectiveSourceMode(?PackageType $type): PackageSourceMode
    {
        if ($type === PackageType::Composer) {
            return PackageSourceMode::Git;
        }

        return PackageSourceMode::tryFrom((string) $this->input('source_mode')) ?? PackageSourceMode::Publish;
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
            'repository_url.starts_with' => 'Die Repository-URL muss mit https:// oder ssh:// beginnen.',
            'repository_url.url' => 'Bitte eine gültige https- oder ssh-Repository-URL angeben.',
            'group_ids.required' => 'Bitte mindestens eine Registry auswählen.',
            'group_ids.min' => 'Bitte mindestens eine Registry auswählen.',
        ];
    }
}
