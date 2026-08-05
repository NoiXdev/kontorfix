<?php

namespace App\Http\Requests\Concerns;

use App\Models\MailSetting;
use Illuminate\Validation\Rule;

/**
 * Shared mail-backend rules. The same fields are submitted by the admin settings form,
 * its test-mail probe and the first-run wizard, so the rules live here instead of being
 * kept in sync across three request classes.
 */
trait ValidatesMailSettings
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function mailSettingRules(): array
    {
        return [
            'mailer' => ['required', 'in:log,smtp,postal'],
            'from_address' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],

            'smtp_host' => ['required_if:mailer,smtp', 'nullable', 'string'],
            'smtp_port' => ['required_if:mailer,smtp', 'nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl'],

            'postal_domain' => ['required_if:mailer,postal', 'nullable', 'url'],
            // Required only while no credential is stored yet — an empty field then
            // means "keep the stored one" rather than "clear it". During the wizard
            // nothing is stored, so this correctly resolves to required.
            'postal_key' => [
                Rule::requiredIf(fn () => $this->input('mailer') === 'postal' && blank(MailSetting::current()->postal_key)),
                'nullable',
                'string',
            ],
        ];
    }

    /**
     * The encryption dropdown submits an empty string for "no encryption". `nullable`
     * does not catch that — the value would still reach the `in:` rule and fail — so
     * the blanks are folded to null before validation.
     */
    protected function normalizeMailInput(): void
    {
        foreach (['smtp_encryption', 'from_address', 'from_name', 'smtp_username'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }
}
