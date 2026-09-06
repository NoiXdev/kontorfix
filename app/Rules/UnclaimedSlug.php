<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Keeps a slug from naming both an organization and a registry.
 *
 * The registry URL is /r/{orgSlug}/{groupSlug}, and old one-segment URLs are answered by a
 * /r/{slug} route registered after it. Laravel matches the first route that fits, so with
 * an organization named `kadenz` the URL /r/kadenz/foo matches the canonical form first and
 * resolves to registry `foo` of organization `kadenz` — instead of the legacy registry
 * `kadenz`. A wrong answer is worse than an error, which is why
 * 2026_09_03_100000_scope_group_slug_to_organization refuses to migrate an instance that
 * already holds such a pair.
 *
 * That refusal only guards the upgrade. This rule is what keeps the invariant afterwards —
 * for the common case. It runs as a validation rule, ahead of the actual insert/update, so
 * two concurrent requests can both read a table that does not yet contain the other's row,
 * both pass, and both write: the check here is check-then-act, not atomic. The authoritative
 * enforcement is `App\Services\Slugs\SlugClaimGuard`, which every write path runs inside a
 * `pg_advisory_xact_lock` immediately before the insert/update. **This rule stays anyway**:
 * it is what produces this class's German message in the interactive, non-racing case
 * (a console form, or two humans who did not submit in the same instant) without a
 * round trip through the guard's transaction, so do not delete it as "redundant" with the
 * guard — the guard is authoritative, this rule is ergonomic, and the app needs both.
 *
 * Both create paths use it. The update paths (editing a registry or an organization slug)
 * need the same rule and no self-exclusion — a row's own slug lives in the other table by
 * definition, so there is nothing to ignore.
 */
class UnclaimedSlug implements ValidationRule
{
    /**
     * Shown when the slug under validation is an organization's and a registry already
     * answers to it. Shared with SlugClaimGuard so the ergonomic (this class) and
     * authoritative (the guard) halves of the invariant never disagree in wording.
     */
    public const TAKEN_BY_REGISTRY = 'Dieser Slug ist bereits als Registry-Slug vergeben. Organisationen und Registries teilen sich einen Namensraum in der Registry-URL — bitte einen anderen Slug wählen.';

    /**
     * Shown when the slug under validation is a registry's and an organization already
     * answers to it. Shared with SlugClaimGuard, for the same reason as above.
     */
    public const TAKEN_BY_ORGANIZATION = 'Dieser Slug ist bereits als Organisations-Slug vergeben. Organisationen und Registries teilen sich einen Namensraum in der Registry-URL — bitte einen anderen Slug wählen.';

    private function __construct(private string $table, private string $message) {}

    /** For an organization slug: refuse it while a registry already answers to it. */
    public static function byRegistry(): self
    {
        return new self('groups', self::TAKEN_BY_REGISTRY);
    }

    /** For a registry slug: refuse it while an organization already answers to it. */
    public static function byOrganization(): self
    {
        return new self('organizations', self::TAKEN_BY_ORGANIZATION);
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (DB::table($this->table)->where('slug', $value)->exists()) {
            $fail($this->message);
        }
    }
}
