<?php

namespace App\Support\Retention;

use App\Models\Package;
use App\Models\RetentionPolicy;

/**
 * What a policy would do, or did, to one package. The one shape the dry-run pages, the
 * portal preview and the activity log all read, so "what the report said" and "what the
 * run logged" cannot drift apart.
 */
final readonly class RetentionReport
{
    /** @param list<RetentionDecision> $decisions */
    private function __construct(
        public Package $package,
        public RetentionPolicy $policy,
        public array $decisions,
    ) {}

    /** @param list<RetentionDecision> $decisions */
    public static function for(Package $package, RetentionPolicy $policy, array $decisions): self
    {
        return new self($package, $policy, $decisions);
    }

    /** @return list<RetentionDecision> */
    public function kept(): array
    {
        return array_values(array_filter($this->decisions, fn (RetentionDecision $decision): bool => $decision->keep));
    }

    /** @return list<RetentionDecision> */
    public function removed(): array
    {
        return array_values(array_filter($this->decisions, fn (RetentionDecision $decision): bool => ! $decision->keep));
    }

    /** @return list<string> */
    public function removedTagNames(): array
    {
        return array_map(fn (RetentionDecision $decision): string => $decision->tag->name, $this->removed());
    }

    /**
     * The flat shape the dry-run pages and the portal render. `reason` is null exactly when
     * the tag is being removed — an empty string would render as a blank cell that reads
     * like a missing value rather than like a decision.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => ['id' => $this->package->id, 'name' => $this->package->name],
            'policy' => ['id' => $this->policy->id, 'name' => $this->policy->name],
            'kept_count' => count($this->kept()),
            'removed_count' => count($this->removed()),
            'tags' => array_map(fn (RetentionDecision $decision): array => [
                'name' => $decision->tag->name,
                'pushed_at' => $decision->tag->pushed_at?->toDateTimeString(),
                'keep' => $decision->keep,
                'reason' => $decision->reasons === [] ? null : implode(', ', $decision->reasons),
            ], $this->decisions),
        ];
    }
}
