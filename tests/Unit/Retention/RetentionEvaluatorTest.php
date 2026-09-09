<?php

use App\Models\OciTag;
use App\Services\Oci\Retention\RetentionEvaluator;
use App\Support\Retention\RetentionDecision;
use App\Support\Retention\RetentionRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** An unsaved tag — the evaluator is pure, so nothing in this file needs a database. */
function retentionTag(string $name, string $pushedAt): OciTag
{
    return new OciTag(['name' => $name, 'pushed_at' => CarbonImmutable::parse($pushedAt)]);
}

function retentionRule(string $type, int|string $value): RetentionRule
{
    return RetentionRule::fromArray(match ($type) {
        'keep_last' => ['type' => $type, 'count' => $value],
        'keep_newer_than_days', 'keep_untagged' => ['type' => $type, 'days' => $value],
        default => ['type' => $type, 'pattern' => $value],
    });
}

/**
 * The tag names on one side of the decision, sorted so assertions read as sets. Sorted
 * NAMES, not decisions in evaluator order: the evaluator is free to order its output any
 * way it likes, and these tests must not accidentally pin that.
 *
 * @param  list<RetentionDecision>  $decisions
 * @return list<string>
 */
function retentionNames(array $decisions, bool $keep): array
{
    $names = array_map(
        fn ($decision) => $decision->tag->name,
        array_filter($decisions, fn ($decision) => $decision->keep === $keep),
    );
    sort($names);

    return $names;
}

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-09-08 12:00:00');
    $this->evaluator = new RetentionEvaluator;
});

it('keeps the N most recently pushed tags and removes the rest', function () {
    $tags = new Collection([
        retentionTag('alt', '2026-01-01 00:00:00'),
        retentionTag('mittel', '2026-06-01 00:00:00'),
        retentionTag('neu', '2026-09-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide([retentionRule('keep_last', 2)], $tags, $this->now);

    expect(retentionNames($decisions, true))->toBe(['mittel', 'neu'])
        ->and(retentionNames($decisions, false))->toBe(['alt']);
});

it('breaks a pushed_at tie deterministically by name descending', function () {
    // Three tags from one multi-tag build, identical to the second. Without a tiebreak
    // "the last 2 of 3" is whatever order the caller handed the collection over in, and
    // this test would be flaky rather than red — which is why it asserts the exact pair.
    $tags = new Collection([
        retentionTag('a', '2026-09-01 00:00:00'),
        retentionTag('b', '2026-09-01 00:00:00'),
        retentionTag('c', '2026-09-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide([retentionRule('keep_last', 2)], $tags, $this->now);

    expect(retentionNames($decisions, true))->toBe(['b', 'c']);
});

it('keeps tags pushed inside the window, boundary included', function () {
    $tags = new Collection([
        retentionTag('frisch', '2026-09-05 00:00:00'),
        retentionTag('grenze', '2026-08-09 12:00:00'),
        retentionTag('knapp-daneben', '2026-08-09 11:59:59'),
        retentionTag('alt', '2026-07-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide([retentionRule('keep_newer_than_days', 30)], $tags, $this->now);

    expect(retentionNames($decisions, true))->toBe(['frisch', 'grenze'])
        ->and(retentionNames($decisions, false))->toBe(['alt', 'knapp-daneben']);
});

it('matches a pattern with * as a glob, not as a regex', function () {
    $tags = new Collection([
        retentionTag('v1.0.0', '2026-01-01 00:00:00'),
        retentionTag('nightly', '2026-01-01 00:00:00'),
        // A regex metacharacter that is NOT a glob wildcard. Under a regex implementation
        // the pattern `v.` would match `v1`; under glob it matches only the literal `v.`.
        retentionTag('v.', '2026-01-01 00:00:00'),
        retentionTag('v1', '2026-01-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide([retentionRule('keep_matching', 'v.')], $tags, $this->now);

    expect(retentionNames($decisions, true))->toBe(['v.']);

    $wildcard = $this->evaluator->decide([retentionRule('keep_matching', 'v*')], $tags, $this->now);

    expect(retentionNames($wildcard, true))->toBe(['v.', 'v1', 'v1.0.0']);
});

it('combines keep-rules with OR, so adding a rule never deletes more', function () {
    $tags = new Collection([
        retentionTag('v1.0.0', '2026-01-01 00:00:00'),   // old, matches v*
        retentionTag('neu', '2026-09-07 00:00:00'),      // fresh, matches nothing
    ]);

    $onlyAge = $this->evaluator->decide([retentionRule('keep_newer_than_days', 30)], $tags, $this->now);
    expect(retentionNames($onlyAge, true))->toBe(['neu']);

    $both = $this->evaluator->decide(
        [retentionRule('keep_newer_than_days', 30), retentionRule('keep_matching', 'v*')],
        $tags,
        $this->now,
    );

    // Under AND this would be empty. That is the whole decision.
    expect(retentionNames($both, true))->toBe(['neu', 'v1.0.0']);
});

it('names the rule that saved each surviving tag', function () {
    $tags = new Collection([retentionTag('v1.0.0', '2026-01-01 00:00:00')]);

    $decisions = $this->evaluator->decide([retentionRule('keep_matching', 'v*')], $tags, $this->now);

    expect($decisions[0]->reasons)->toBe(['Passend zu Muster „v*“']);
});

it('names every rule that keeps a tag, not just the first', function () {
    // The reason column is how an operator spots the GAP in a policy — a v* pattern with
    // no age limit is obvious the moment an eight-month-old release shows up held by it.
    // Reporting only the first matching rule would hide exactly that.
    $tags = new Collection([retentionTag('v1.0.0', '2026-09-07 00:00:00')]);

    $decisions = $this->evaluator->decide(
        [retentionRule('keep_newer_than_days', 30), retentionRule('keep_matching', 'v*')],
        $tags,
        $this->now,
    );

    expect($decisions[0]->reasons)->toBe(['Jünger als 30 Tage', 'Passend zu Muster „v*“']);
});

it('removes nothing at all when the policy has no keep-rule', function () {
    // A shield-only policy. Under a flat four-way OR this would keep prod-1 and DELETE the
    // other two — an operator who added a shield would have configured a deletion.
    $tags = new Collection([
        retentionTag('prod-1', '2026-01-01 00:00:00'),
        retentionTag('alt', '2026-01-01 00:00:00'),
        retentionTag('aelter', '2025-01-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide([retentionRule('never_delete', 'prod-*')], $tags, $this->now);

    expect(retentionNames($decisions, false))->toBe([])
        ->and(retentionNames($decisions, true))->toBe(['aelter', 'alt', 'prod-1']);
});

it('removes nothing for an empty rule list', function () {
    $tags = new Collection([retentionTag('alt', '2020-01-01 00:00:00')]);

    $decisions = $this->evaluator->decide([], $tags, $this->now);

    expect(retentionNames($decisions, false))->toBe([])
        ->and($decisions[0]->reasons)->toBe([RetentionEvaluator::NO_KEEP_RULE]);
});

it('lets a shield override every other rule', function () {
    $tags = new Collection([
        retentionTag('prod-1', '2020-01-01 00:00:00'),   // ancient, matches no keep-rule
        retentionTag('alt', '2020-01-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide(
        [retentionRule('keep_newer_than_days', 30), retentionRule('never_delete', 'prod-*')],
        $tags,
        $this->now,
    );

    expect(retentionNames($decisions, true))->toBe(['prod-1'])
        ->and(retentionNames($decisions, false))->toBe(['alt']);
});

it('does not let a shield consume a keep_last slot', function () {
    // THE test that distinguishes the two readings, and the fixture exists to make them
    // distinguishable:
    //   shield excluded from the ranking -> prod-1, c, b survive (3 tags)
    //   shield included in the ranking   -> prod-1, c survive    (2 tags)
    // Adding a shield must never cause an extra deletion — the same principle the OR
    // decision rests on.
    $tags = new Collection([
        retentionTag('prod-1', '2026-09-04 00:00:00'),
        retentionTag('c', '2026-09-03 00:00:00'),
        retentionTag('b', '2026-09-02 00:00:00'),
        retentionTag('a', '2026-09-01 00:00:00'),
    ]);

    $decisions = $this->evaluator->decide(
        [retentionRule('keep_last', 2), retentionRule('never_delete', 'prod-*')],
        $tags,
        $this->now,
    );

    expect(retentionNames($decisions, true))->toBe(['b', 'c', 'prod-1'])
        ->and(retentionNames($decisions, false))->toBe(['a']);
});

it('gives a shielded tag the shield as its reason, not a keep-rule', function () {
    // prod-1 matches BOTH the shield and the keep-pattern. The report has to say the
    // shield saved it: the shield is why the tag cannot age out, and naming the weaker
    // reason would suggest that removing the pattern rule endangers the tag.
    $tags = new Collection([retentionTag('prod-1', '2026-01-01 00:00:00')]);

    $decisions = $this->evaluator->decide(
        [retentionRule('keep_matching', 'prod-*'), retentionRule('never_delete', 'prod-*')],
        $tags,
        $this->now,
    );

    expect($decisions[0]->reasons)->toBe(['Nie löschen: „prod-*“']);
});

it('rejects a rule whose type it does not know', function () {
    expect(fn () => RetentionRule::fromArray(['type' => 'delete_everything']))
        ->toThrow(ValueError::class);
});

it('rejects a rule whose value is missing, malformed or out of range', function () {
    expect(fn () => RetentionRule::fromArray(['type' => 'keep_last']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => RetentionRule::fromArray(['type' => 'keep_last', 'count' => 0]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => RetentionRule::fromArray(['type' => 'keep_last', 'count' => 'zehn']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => RetentionRule::fromArray(['type' => 'keep_newer_than_days', 'days' => -1]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => RetentionRule::fromArray(['type' => 'keep_matching', 'pattern' => '']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => RetentionRule::fromArray(['type' => 'never_delete', 'pattern' => '   ']))
        ->toThrow(InvalidArgumentException::class);
});

it('round-trips a rule through toArray', function () {
    $raw = ['type' => 'keep_last', 'count' => 10];

    expect(RetentionRule::fromArray($raw)->toArray())->toBe($raw);
});

it('builds and describes a keep_untagged rule, one per set semantics live elsewhere', function () {
    $rule = RetentionRule::fromArray(['type' => 'keep_untagged', 'days' => 14]);

    expect($rule->days)->toBe(14)
        ->and($rule->type->affectsTags())->toBeFalse()
        ->and($rule->describe())->toBe('Ungetaggte behalten: 14 Tage')
        ->and($rule->toArray())->toBe(['type' => 'keep_untagged', 'days' => 14]);

    expect(fn () => RetentionRule::fromArray(['type' => 'keep_untagged', 'days' => 0]))
        ->toThrow(InvalidArgumentException::class);
});

it('never lets keep_untagged decide a tag', function () {
    // The rule decides MANIFESTS, not tags. As a tag keep-rule it would keep every tag
    // (or none, depending on the reading) — either way it must not participate in the OR
    // and must never appear in a reason.
    $tags = new Collection([
        retentionTag('alt', '2020-01-01 00:00:00'),
        retentionTag('neu', '2026-09-07 00:00:00'),
    ]);

    $onlyUntagged = $this->evaluator->decide(
        [retentionRule('keep_untagged', 14)],
        $tags,
        $this->now,
    );

    // No tag keep-rule in the set -> removes nothing (the shield-only invariant extends).
    expect(retentionNames($onlyUntagged, false))->toBe([]);

    $mixed = $this->evaluator->decide(
        [retentionRule('keep_newer_than_days', 30), retentionRule('keep_untagged', 14)],
        $tags,
        $this->now,
    );

    expect(retentionNames($mixed, false))->toBe(['alt'])
        // The kept tag's reason names the age rule, never the untagged rule.
        ->and(collect($mixed)->firstWhere('keep', true)->reasons)->toBe(['Jünger als 30 Tage']);
});
