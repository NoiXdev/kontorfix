<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The slug grammar for organizations and registries: lowercase letters, digits and hyphens,
 * but never a leading or a trailing hyphen.
 *
 * **This pattern and `$ociName` in routes/registry.php have to agree, and this is the half
 * that has to give.** A registry is addressable by path namespace on the instance's own host
 * (`<instance>/v2/<org>/<registry>/<repo>`, see App\Http\Middleware\ResolveOciContext), so
 * both slugs become path components of an OCI repository name — and the OCI distribution
 * spec's name grammar, which `$ociName` transcribes, allows a hyphen only BETWEEN
 * alphanumerics (`[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*`). The looser `^[a-z0-9-]+$` this
 * rule replaces admitted `acme-` and `-acme`, which no Docker client can express at all:
 * `docker pull registry.example.com/acme-/intern/meinapp` is not a reference the client will
 * even send, and the request that does reach this application misses every `/v2` route and
 * falls to the bare `{}` 404 of the fallback, which explains nothing.
 *
 * The fix belongs here rather than in the route: `$ociName` IS the OCI grammar, so relaxing
 * it would only move the failure from an unexplained 404 to a repository name this registry
 * accepts and no client can name. Consecutive hyphens INSIDE a slug (`a--b`) stay legal —
 * `-+` allows them — so only the two ends are refused.
 *
 * Existing slugs are deliberately not migrated or rewritten: an instance that already holds
 * `acme-` keeps it (and keeps being reachable at `/r/acme-/intern`, which has no such
 * grammar), which is why the update paths pass their row's CURRENT slug as `$unchanged`.
 * A save that leaves the address alone must not be refused for a rule that did not exist
 * when the row was written; only a NEW value has to be addressable.
 */
class AddressableSlug implements ValidationRule
{
    /** Anything outside the slug character set at all. */
    public const INVALID_CHARACTERS = 'Der Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.';

    /** The addressability rule this class exists for. */
    public const HYPHEN_AT_EDGE = 'Der Slug darf nicht mit einem Bindestrich beginnen oder enden, da er sonst nicht als Docker-Adresse verwendbar ist. Bitte wählen Sie einen anderen Slug.';

    /**
     * @param  string|null  $unchanged  The slug the row being updated already carries, which
     *                                  passes even when it predates this rule. Null on every
     *                                  create path, where there is no previous value.
     */
    public function __construct(private readonly ?string $unchanged = null) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $value === $this->unchanged) {
            return;
        }

        if (preg_match('/^[a-z0-9-]+$/', $value) !== 1) {
            $fail(self::INVALID_CHARACTERS);

            return;
        }

        if (preg_match('/^[a-z0-9]+(?:-+[a-z0-9]+)*$/', $value) !== 1) {
            $fail(self::HYPHEN_AT_EDGE);
        }
    }
}
