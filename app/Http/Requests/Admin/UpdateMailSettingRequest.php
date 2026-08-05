<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesMailSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailSettingRequest extends FormRequest
{
    use ValidatesMailSettings;

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
        return $this->mailSettingRules();
    }
}
