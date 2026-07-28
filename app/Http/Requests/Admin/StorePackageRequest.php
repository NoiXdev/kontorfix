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
        // Name format per type: Composer is always vendor/name; npm is a plain
        // name or @scope/name (scoped — the normal case).
        $nameRegex = $this->input('type') === PackageType::Npm->value
            ? '/^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/'
            : '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/';

        return [
            'type' => ['required', Rule::enum(PackageType::class)],
            'name' => [
                'required',
                'string',
                'max:190',
                "regex:{$nameRegex}",
                Rule::unique('packages')->where('type', $this->input('type')),
            ],
            // Only real Git remotes over https/ssh — no file:// or gopher:// etc.,
            // which would otherwise be passed to the git subprocess as an SSRF surface.
            'repository_url' => ['required', 'string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'],
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
