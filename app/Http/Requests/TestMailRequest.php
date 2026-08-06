<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMailSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a mail configuration that is not persisted yet, plus the probe recipient.
 * Shared by the admin settings screen and the first-run wizard — both need to prove the
 * backend works before saving it.
 */
class TestMailRequest extends FormRequest
{
    use ValidatesMailSettings;

    /**
     * Reachability is decided by middleware: `super` for the admin route,
     * EnsureSetupIncomplete for the wizard route.
     */
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
        return [
            ...$this->mailSettingRules(),
            'recipient' => ['required', 'email'],
        ];
    }
}
