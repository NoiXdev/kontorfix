<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMailSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreSetupRequest extends FormRequest
{
    use ValidatesMailSettings;

    /**
     * Reachability is decided by the EnsureSetupIncomplete middleware — it is the
     * single place that knows the wizard may only run on an instance without users.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeMailInput();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Admin account. No `unique` rule needed: the wizard only ever runs
            // against an empty users table.
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'admin_password' => ['required', 'confirmed', Password::defaults()],

            'organization_name' => ['required', 'string', 'max:190'],

            'registry_name' => ['required', 'string', 'max:190'],
            'registry_slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/', 'unique:groups,slug'],
            'registry_public' => ['boolean'],

            // Mail rules are shared with the admin settings screen and the test probe.
            ...$this->mailSettingRules(),

            'storage_driver' => ['required', 'in:local,s3'],
            'storage_key' => ['required_if:storage_driver,s3', 'nullable', 'string'],
            'storage_secret' => ['required_if:storage_driver,s3', 'nullable', 'string'],
            'storage_region' => ['required_if:storage_driver,s3', 'nullable', 'string'],
            'storage_bucket' => ['required_if:storage_driver,s3', 'nullable', 'string'],
            'storage_endpoint' => ['nullable', 'url'],
            'storage_url' => ['nullable', 'url'],
            'storage_use_path_style' => ['boolean'],
        ];
    }
}
