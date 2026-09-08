<?php

namespace App\Support\Retention;

use App\Models\RetentionPolicy;

/**
 * What the resolution chain answered for one package: the rules that apply, which tier
 * they came from, and — when the tier is a named policy — the policy itself.
 *
 * Exists because the chain's answer stopped being a policy the moment inline rules became
 * tier 0: an anonymous rule set has no RetentionPolicy row, and every consumer that used
 * to print `$policy->name` needs a label that cannot be null. label() is that one string —
 * the admin card, the dry run, the portal and the activity log all read it, so an
 * anonymous rule set is never rendered as a nameless hole.
 */
final readonly class ResolvedRetention
{
    public const TIER_INLINE = 'inline';

    public const TIER_PACKAGE = 'package';

    public const TIER_INSTANCE = 'instance';

    /** @param list<RetentionRule> $rules */
    public function __construct(
        public array $rules,
        public string $tier,
        public ?RetentionPolicy $policy,
    ) {}

    /** The German name every surface prints for this rule set. */
    public function label(): string
    {
        // An explicit ternary, not `?->name ?? …`: PHPStan flags the nullsafe operator as
        // redundant on the left of `??` (isset semantics already swallow the null), and a
        // bare `->name` would read as an unguarded dereference.
        return $this->policy !== null ? $this->policy->name : 'Eigene Regeln (nur dieses Paket)';
    }

    /**
     * The untagged-manifest window in days, or null when no rule sets one. Validation
     * refuses two keep_untagged rules per set, so "the first" is "the only".
     */
    public function untaggedKeepDays(): ?int
    {
        foreach ($this->rules as $rule) {
            if (! $rule->type->affectsTags()) {
                return $rule->days;
            }
        }

        return null;
    }

    /**
     * The rules in words — RetentionRule::describe(), the one wording the operator's dry
     * run and the customer's portal share.
     *
     * @return list<string>
     */
    public function describedRules(): array
    {
        return array_map(fn (RetentionRule $rule): string => $rule->describe(), $this->rules);
    }
}
