<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                // The instance's own host must not become a registry root: the web routes
                // are registered first and win, but every unclaimed path would fall through
                // to the registry routes and serve one group's packages off the console
                // hostname. An unset/unparseable APP_URL yields an empty list, i.e. no rule.
                Rule::notIn($this->reservedHostnames()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hostname.not_in' => 'Der eigene Host der Instanz kann keiner Registry zugeordnet werden.',
        ];
    }

    /**
     * Hostnames the instance uses for itself and therefore cannot hand to a registry.
     *
     * @return list<string>
     */
    private function reservedHostnames(): array
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $appHost === '' ? [] : [$appHost];
    }
}
