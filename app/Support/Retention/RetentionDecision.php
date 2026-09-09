<?php

namespace App\Support\Retention;

use App\Models\OciTag;

/**
 * One tag's outcome under a rule set, with the rules that produced it.
 *
 * $reasons carries EVERY rule that keeps the tag, not just the first: the reason column is
 * how an operator spots the gap in a policy (a v* pattern with no age limit is obvious the
 * moment an eight-month-old release shows up held by it), and reporting one arbitrary
 * winner would hide exactly that. Empty precisely when the tag is being removed — a
 * removal has no reason to keep, and rendering distinguishes the two by this rather than
 * by a sentinel string.
 */
final readonly class RetentionDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public OciTag $tag,
        public bool $keep,
        public array $reasons,
    ) {}

    /**
     * The one shape every preview/dry-run JSON response renders a decision as — the policy
     * preview endpoint and the package-scoped one both build their `tags` array from this,
     * so the two surfaces cannot drift on what a row looks like.
     *
     * @return array{name: string, pushed_at: string|null, keep: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->tag->name,
            'pushed_at' => $this->tag->pushed_at?->toDateTimeString(),
            'keep' => $this->keep,
            'reason' => $this->reasons === [] ? null : implode(', ', $this->reasons),
        ];
    }
}
