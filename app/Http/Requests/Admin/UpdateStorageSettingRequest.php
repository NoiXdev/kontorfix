<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'secret' => ['nullable', 'string'],
            'endpoint' => ['nullable', 'url'],
            'url' => ['nullable', 'url'],
            'use_path_style' => ['boolean'],
        ];
    }
}
