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
 * That refusal only guards the upgrade. This rule is what keeps the invariant afterwards:
 * without it the collision can be re-created from the admin console the next day, and the
 * legacy redirect starts serving the wrong registry with nothing to say so.
 *
 * Both create paths use it. The update paths (editing a registry or an organization slug)
 * need the same rule and no self-exclusion — a row's own slug lives in the other table by
 * definition, so there is nothing to ignore.
 */
class UnclaimedSlug implements ValidationRule
{
    private function __construct(private string $table, private string $message) {}

    /** For an organization slug: refuse it while a registry already answers to it. */
    public static function byRegistry(): self
    {
        return new self(
            'groups',
            'Dieser Slug ist bereits als Registry-Slug vergeben. Organisationen und Registries teilen sich einen Namensraum in der Registry-URL — bitte einen anderen Slug wählen.'
        );
    }

    /** For a registry slug: refuse it while an organization already answers to it. */
    public static function byOrganization(): self
    {
        return new self(
            'organizations',
            'Dieser Slug ist bereits als Organisations-Slug vergeben. Organisationen und Registries teilen sich einen Namensraum in der Registry-URL — bitte einen anderen Slug wählen.'
        );
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
