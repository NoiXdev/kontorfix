<?php

namespace App\Http\Requests\Admin;

use App\Models\StorageSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStorageSettingRequest extends FormRequest
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
            'driver' => ['required', 'in:local,s3'],
            'key' => ['required_if:driver,s3', 'nullable', 'string'],
            'region' => ['required_if:driver,s3', 'nullable', 'string'],
            'bucket' => ['required_if:driver,s3', 'nullable', 'string'],
            // For S3, a secret is required AS LONG AS none is stored yet —
            // otherwise an empty secret would save an immediately non-functional disk.
            // If a secret is already stored, "empty" still means "keep".
            'secret' => [
                Rule::requiredIf(fn () => $this->input('driver') === 's3' && blank(StorageSetting::current()->secret)),
                'nullable',
                'string',
            ],
            'endpoint' => ['nullable', 'url'],
            'url' => ['nullable', 'url'],
            'use_path_style' => ['boolean'],
        ];
    }
}
