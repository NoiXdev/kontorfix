<?php

namespace App\Services\Mail;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailManager
{
    public function current(): MailSetting
    {
        return MailSetting::current();
    }

    /**
     * Builds the config overrides for a (possibly unsaved) setting. Returning a flat
     * config array — instead of writing straight to config() — is what lets the admin
     * UI test a planned configuration before persisting it.
     *
     * @return array<string,mixed>
     */
    public function configFor(MailSetting $s): array
    {
        $config = [
            'mail.default' => $s->mailer,
        ];

        // Leave the .env-configured sender in place when the setting is blank,
        // otherwise Symfony rejects the message for lack of a From address.
        if (filled($s->from_address)) {
            $config['mail.from.address'] = $s->from_address;
        }

        if (filled($s->from_name)) {
            $config['mail.from.name'] = $s->from_name;
        }

        if ($s->mailer === 'smtp') {
            $config['mail.mailers.smtp.host'] = $s->smtp_host;
            $config['mail.mailers.smtp.port'] = $s->smtp_port;
            $config['mail.mailers.smtp.username'] = $s->smtp_username ?: null;
            $config['mail.mailers.smtp.password'] = $s->smtp_password ?: null;
            // Laravel derives TLS from the scheme: 'smtps' forces implicit TLS,
            // null lets the transport negotiate STARTTLS opportunistically.
            $config['mail.mailers.smtp.scheme'] = $s->smtp_encryption === 'ssl' ? 'smtps' : null;
        }

        if ($s->mailer === 'postal') {
            $config['postal.domain'] = $s->postal_domain;
            $config['postal.key'] = $s->postal_key;
        }

        return $config;
    }

    /** Applies the persisted settings to the running app. */
    public function apply(): void
    {
        config($this->configFor($this->current()));
    }

    /**
     * Whether the active transport can actually put a message in front of a recipient.
     *
     * `log` is the setup wizard's default ("kein Versand — später konfigurierbar") and
     * `array`/`null` are test and disabled transports: they all accept a message and
     * throw nothing while nobody ever receives it. Any flow that would take something
     * away from a user until they act on a mail has to know the difference, or it locks
     * the user out of a door that has no key.
     */
    public function canDeliver(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', 'null', ''], true);
    }

    /**
     * Builds an unsaved setting from validated input so a configuration can be probed
     * before it is persisted. Blank secret fields fall back to the stored values, which
     * is what lets the admin form re-test without retyping the password.
     *
     * @param  array<string,mixed>  $data
     */
    public function fromInput(array $data): MailSetting
    {
        $current = $this->current();

        $setting = new MailSetting;
        $setting->fill(collect($data)->except(['smtp_password', 'postal_key', 'recipient'])->all());

        $setting->smtp_password = filled($data['smtp_password'] ?? null)
            ? (string) $data['smtp_password']
            : $current->smtp_password;

        $setting->postal_key = filled($data['postal_key'] ?? null)
            ? (string) $data['postal_key']
            : $current->postal_key;

        return $setting;
    }

    /**
     * Sends a probe mail through an arbitrary setting, restoring the persisted
     * config afterwards so a failed test cannot leak into subsequent requests.
     *
     * @return array{ok:bool,message:string}
     */
    public function sendTest(MailSetting $setting, string $recipient): array
    {
        try {
            config($this->configFor($setting));
            // The mailer is resolved once per manager instance and caches the
            // transport, so it has to be rebuilt for the probe config to take effect.
            Mail::forgetMailers();

            Mail::raw(
                'Test message from Kontorfix. If this arrived, the mail configuration works.',
                fn ($message) => $message->to($recipient)->subject('Kontorfix — mail test'),
            );

            return ['ok' => true, 'message' => "Testmail an {$recipient} versendet."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $this->apply();
            Mail::forgetMailers();
        }
    }
}
