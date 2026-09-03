<?php

namespace App\Http\Requests\Admin;

use App\Rules\UnclaimedSlug;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:190'],
            // Unique among organizations, and never equal to a registry slug: the two share
            // one namespace in the registry URL. See App\Rules\UnclaimedSlug.
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/', 'unique:organizations,slug', UnclaimedSlug::byRegistry()],
        ];
    }
}
