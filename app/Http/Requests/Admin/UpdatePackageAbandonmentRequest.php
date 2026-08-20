<?php

namespace App\Http\Requests\Admin;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageAbandonmentRequest extends FormRequest
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
        /** @var Package $package */
        $package = $this->route('package');

        return [
            'abandoned' => ['required', 'boolean'],
            'replacement_package' => [
                'nullable', 'string', 'max:255',
                // The replacement is a package name in the same ecosystem, so it obeys the
                // same name format. The regex lives on the enum already — reuse it rather
                // than restating the format here, where it would drift.
                'regex:'.$package->type->nameRegex(),
            ],
            'abandonment_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
