<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/', 'unique:groups,slug'],
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
}
