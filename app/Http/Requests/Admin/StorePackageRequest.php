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
        // Namensformat je Typ: Composer ist immer vendor/name; npm ist ein bloßer
        // Name oder @scope/name (scoped — der Normalfall).
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
            // Nur echte Git-Remotes über https/ssh — kein file:// oder gopher:// etc.,
            // das sonst als SSRF-Fläche an den git-Subprozess weitergereicht würde.
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
