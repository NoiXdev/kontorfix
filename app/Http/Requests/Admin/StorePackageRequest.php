<?php

namespace App\Http\Requests\Admin;

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

        // Git-synced types (Composer) require a repository; publish-based types (npm,
        // Python) are filled by pushing artifacts, so the repository is optional.
        $repositoryRule = $type !== null && $type->isPublishBased()
            ? ['nullable', 'string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://']
            // Only real Git remotes over https/ssh — no file:// or gopher:// etc., which
            // would otherwise be passed to the git subprocess as an SSRF surface.
            : ['required', 'string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'];

        return [
            'type' => ['required', Rule::enum(PackageType::class)],
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
