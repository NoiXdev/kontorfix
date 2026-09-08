<?php

namespace App\Support\Retention;

use App\Enums\RetentionRuleType;
use App\Models\OciTag;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One rule of a retention policy, validated at construction.
 *
 * Validated HERE rather than only in the form request, because the form is not the only
 * writer of `retention_policies.rules`: a seeder, a future API, a hand-edited jsonb value
 * or a migration all reach the evaluator without passing a request. A rule that cannot be
 * built cannot be evaluated, which is the failure mode this class exists to guarantee — the
 * alternative is an unparseable rule silently contributing nothing to an OR, i.e. a policy
 * deleting more than the operator configured.
 */
final readonly class RetentionRule
{
    private function __construct(
        public RetentionRuleType $type,
        public ?int $count,
        public ?int $days,
        public ?string $pattern,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        // ValueError from the enum for an unknown type, deliberately not caught and
        // rewrapped: the caller has to handle "this is not a rule at all" either way, and
        // one exception type per failure shape is easier to test than one opaque type for
        // both.
        $type = RetentionRuleType::from((string) ($raw['type'] ?? ''));

        return match ($type) {
            RetentionRuleType::KeepLast => new self($type, self::positiveInt($raw, 'count'), null, null),
            RetentionRuleType::KeepNewerThanDays => new self($type, null, self::positiveInt($raw, 'days'), null),
            RetentionRuleType::KeepMatching,
            RetentionRuleType::NeverDelete => new self($type, null, null, self::pattern($raw)),
        };
    }

    /** @param array<string, mixed> $raw */
    private static function positiveInt(array $raw, string $key): int
    {
        $value = $raw[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Rule field '{$key}' must be a positive integer.");
        }

        $value = (int) $value;

        if ($value < 1) {
            throw new InvalidArgumentException("Rule field '{$key}' must be at least 1.");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function pattern(array $raw): string
    {
        $value = $raw['pattern'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Rule field 'pattern' must be a non-empty string.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'count' => $this->count,
            'days' => $this->days,
            'pattern' => $this->pattern,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Glob, not regex, and `Str::is()` is what makes that true rather than a comment: an
     * operator-supplied regex on a delete path lets `.` match everything and a pathological
     * pattern stall the scheduler. `*` is the only wildcard, which is also the only thing
     * the editor's help text promises — a pattern language that accepts more than it
     * documents is a pattern language nobody can predict.
     */
    public function matches(string $tagName): bool
    {
        return $this->pattern !== null && Str::is($this->pattern, $tagName);
    }

    /**
     * Whether this rule keeps $tag. $rank is the tag's 0-based position among the
     * CANDIDATES ordered newest-push-first — candidates, not all tags, because a shielded
     * tag is removed from the ranking before it is built (see RetentionEvaluator, where
     * that ordering is the safety property).
     */
    public function keeps(OciTag $tag, int $rank, CarbonImmutable $now): bool
    {
        return match ($this->type) {
            RetentionRuleType::KeepLast => $rank < (int) $this->count,
            RetentionRuleType::KeepNewerThanDays => CarbonImmutable::parse($tag->pushed_at)
                ->greaterThanOrEqualTo($now->subDays((int) $this->days)),
            RetentionRuleType::KeepMatching, RetentionRuleType::NeverDelete => $this->matches($tag->name),
        };
    }

    /**
     * The reason column's text, German — it is rendered to the operator in the dry run and
     * to the customer in the portal, and it is one string so the two cannot drift.
     */
    public function describe(): string
    {
        return match ($this->type) {
            RetentionRuleType::KeepLast => "Letzte {$this->count} behalten",
            RetentionRuleType::KeepNewerThanDays => "Jünger als {$this->days} Tage",
            RetentionRuleType::KeepMatching => "Passend zu Muster „{$this->pattern}“",
            RetentionRuleType::NeverDelete => "Nie löschen: „{$this->pattern}“",
        };
    }
}
