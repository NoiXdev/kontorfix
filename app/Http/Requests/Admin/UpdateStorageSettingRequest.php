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
            // `exclude_unless` drops the S3 fields from validation when the local
            // driver is chosen, so a stale value in a now-hidden field (e.g. a
            // half-typed endpoint) can no longer fail validation on an invisible field.
            'driver' => ['required', 'in:local,s3'],
            'key' => ['exclude_unless:driver,s3', 'required', 'string'],
            'region' => ['exclude_unless:driver,s3', 'required', 'string'],
            'bucket' => ['exclude_unless:driver,s3', 'required', 'string'],
            // For S3, a secret is required AS LONG AS none is stored yet —
            // otherwise an empty secret would save an immediately non-functional disk.
            // If a secret is already stored, "empty" still means "keep".
            'secret' => [
                'exclude_unless:driver,s3',
                Rule::requiredIf(fn () => blank(StorageSetting::current()->secret)),
                'nullable',
                'string',
            ],
            'endpoint' => ['exclude_unless:driver,s3', 'nullable', 'url'],
            'url' => ['exclude_unless:driver,s3', 'nullable', 'url'],
            'use_path_style' => ['boolean'],
        ];
    }
}
