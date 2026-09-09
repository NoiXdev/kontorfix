<?php

namespace App\Services\Oci\Retention;

use App\Models\OciTag;
use App\Support\Retention\RetentionDecision;
use App\Support\Retention\RetentionRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a rule set plus a package's tags into a per-tag decision with a reason. Pure: it
 * reads no database, writes nothing, and takes `$now` as an argument rather than calling
 * now() — which is what lets the age rule be tested at a fixed instant instead of near one.
 *
 * The evaluation ORDER is the safety property of this whole feature, and it is not the
 * obvious one:
 *
 *   1. Shields (`never_delete`) keep their matches and are taken OUT of the candidate set.
 *   2. Keep-rules are OR'd over what is left.
 *   3. A rule set with no keep-rule removes nothing.
 *   4. What is still a candidate afterwards is removed.
 *
 * Two consequences that no flatter arrangement gives:
 *
 *   - A shield-only policy is INERT. Under a flat four-way OR, `never_delete: prod-*` alone
 *     would keep the prod tags and delete every other tag in the repository, so an operator
 *     adding a shield would have configured a deletion.
 *   - A shield never consumes a `keep_last` slot, because step 1 runs before the ranking in
 *     step 2 is built. Under the other reading, adding a shield to a `keep_last: 2` policy
 *     deletes one more tag than before — and "no configuration change may make this feature
 *     delete more" is the principle the OR decision itself rests on.
 *
 * Both consequences are pinned by tests built to fail under the wrong reading
 * (tests/Unit/Retention/RetentionEvaluatorTest.php).
 */
final class RetentionEvaluator
{
    /**
     * The reason given for a tag kept only because the policy expresses no deletion at all.
     * A visible reason rather than an empty list: an empty list is this class's encoding of
     * "removed", and a kept tag with no stated reason renders as a bug.
     */
    public const NO_KEEP_RULE = 'Keine Behalte-Regel — es wird nichts entfernt';

    /**
     * @param  list<RetentionRule>  $rules
     * @param  Collection<int, OciTag>  $tags
     * @return list<RetentionDecision>
     */
    public function decide(array $rules, Collection $tags, CarbonImmutable $now): array
    {
        $shields = array_values(array_filter($rules, fn (RetentionRule $rule): bool => $rule->type->isShield()));
        $keeps = array_values(array_filter(
            $rules,
            // Not merely "not a shield": keep_untagged decides manifests, not tags, and as
            // a member of this OR it would keep every tag or none.
            fn (RetentionRule $rule): bool => ! $rule->type->isShield() && $rule->type->affectsTags(),
        ));

        $decisions = [];
        $candidates = [];

        foreach ($tags as $tag) {
            $shielded = [];

            foreach ($shields as $shield) {
                if ($shield->matches($tag->name)) {
                    $shielded[] = $shield->describe();
                }
            }

            if ($shielded !== []) {
                // The shield is the reason, even when a keep-rule would also match: the
                // shield is why this tag cannot age out, and naming the weaker reason would
                // suggest that deleting the keep-rule endangers the tag.
                $decisions[] = new RetentionDecision($tag, true, $shielded);

                continue;
            }

            $candidates[] = $tag;
        }

        if ($keeps === []) {
            foreach ($candidates as $tag) {
                $decisions[] = new RetentionDecision($tag, true, [self::NO_KEEP_RULE]);
            }

            return $decisions;
        }

        // Newest push first, then name descending — the same tiebreak
        // OciTag::scopeInPullOrder() uses (over its own column). Deterministic is the
        // property that matters: tags pushed in the same second are the ordinary outcome of
        // one multi-tag build, and without the tiebreak "the last 2 of 3" is whatever order
        // the caller handed the collection over in.
        usort($candidates, fn (OciTag $a, OciTag $b): int => [
            CarbonImmutable::parse($b->pushed_at)->getTimestamp(), $b->name,
        ] <=> [
            CarbonImmutable::parse($a->pushed_at)->getTimestamp(), $a->name,
        ]);

        foreach ($candidates as $rank => $tag) {
            $reasons = [];

            foreach ($keeps as $rule) {
                if ($rule->keeps($tag, $rank, $now)) {
                    $reasons[] = $rule->describe();
                }
            }

            $decisions[] = new RetentionDecision($tag, $reasons !== [], $reasons);
        }

        return $decisions;
    }
}
