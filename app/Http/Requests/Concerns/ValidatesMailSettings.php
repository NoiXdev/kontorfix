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

            // `exclude_unless` drops these from validation AND from validated() when
            // the mailer isn't the matching one — so a stale value left in a now-hidden
            // field (e.g. an invalid smtp_host after switching back to 'log') can never
            // trip the `url`/`in:` rules and produce an error on an invisible field.
            'smtp_host' => ['exclude_unless:mailer,smtp', 'required', 'string'],
            'smtp_port' => ['exclude_unless:mailer,smtp', 'required', 'integer', 'between:1,65535'],
            'smtp_username' => ['exclude_unless:mailer,smtp', 'nullable', 'string'],
            'smtp_password' => ['exclude_unless:mailer,smtp', 'nullable', 'string'],
            'smtp_encryption' => ['exclude_unless:mailer,smtp', 'nullable', 'in:tls,ssl'],

            'postal_domain' => ['exclude_unless:mailer,postal', 'required', 'url'],
            // Required only while no credential is stored yet — an empty field then
            // means "keep the stored one" rather than "clear it". During the wizard
            // nothing is stored, so this correctly resolves to required.
            'postal_key' => [
                'exclude_unless:mailer,postal',
                Rule::requiredIf(fn () => blank(MailSetting::current()->postal_key)),
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
