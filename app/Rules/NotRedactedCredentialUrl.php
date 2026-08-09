<?php

namespace App\Rules;

use App\Support\CredentialUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses a URL whose userinfo is the redaction marker.
 *
 * Surfaces below the tier that stored a credential see `https://***@host/…` instead of
 * the real value (see CredentialUrl). Without this rule, a client that read such a value
 * and posted it back would overwrite a working credential with a literal `***` — the
 * secret would be destroyed and the resulting sync/mirror failure would look like an
 * upstream outage. Refusing the write keeps the stored value intact and tells the
 * operator what to do.
 */
class NotRedactedCredentialUrl implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && CredentialUrl::isRedacted($value)) {
            $fail('Die URL enthält noch den Platzhalter für ausgeblendete Zugangsdaten. Bitte die vollständige URL eintragen oder das Feld unverändert lassen.');
        }
    }
}
