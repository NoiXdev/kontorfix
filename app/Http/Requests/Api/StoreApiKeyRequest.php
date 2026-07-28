<?php

namespace App\Http\Requests\Api;

use App\Enums\ApiKeyPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'permission' => ['required', Rule::enum(ApiKeyPermission::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
