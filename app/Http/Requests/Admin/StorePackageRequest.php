<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
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
            ['string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'],
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
            'group_ids' => ['array'],
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
        ];
    }
}
