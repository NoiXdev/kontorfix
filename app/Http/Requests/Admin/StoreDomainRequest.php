<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hostname' => strtolower(trim((string) $this->input('hostname'))),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'group_id' => ['required', 'uuid', 'exists:groups,id'],
            'hostname' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                'unique:domains,hostname',
            ],
        ];
    }
}
