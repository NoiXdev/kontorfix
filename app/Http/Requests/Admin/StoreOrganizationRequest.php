<?php

namespace App\Http\Requests\Admin;

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
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/', 'unique:organizations,slug'],
        ];
    }
}
