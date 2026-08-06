<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
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
        // Name format per type: Composer is always vendor/name; npm is a plain name or
        // @scope/name; Python is a PEP 508 project name (letters/digits with . _ - inside).
        $nameRegex = match ($this->input('type')) {
            PackageType::Npm->value => '/^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/',
            PackageType::Python->value => '/^([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$/',
            default => '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/',
        };

        // Git-synced types (Composer) require a repository; publish-based types (npm,
        // Python) are filled by pushing artifacts, so the repository is optional.
        $type = PackageType::tryFrom((string) $this->input('type'));
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
            'group_ids' => ['array'],
            'group_ids.*' => ['uuid', 'exists:groups,id'],
        ];
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
