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
            // Bei S3 ist ein Secret Pflicht, SOLANGE noch keins gespeichert ist —
            // sonst würde ein leeres Secret eine sofort funktionsunfähige Disk speichern.
            // Ist bereits ein Secret hinterlegt, bedeutet „leer" weiterhin „behalten".
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
