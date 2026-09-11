<?php

namespace App\Support\Licence;

/**
 * The version range a single licence window grants — `group_package.version_min` and
 * `version_max`, read together rather than as two loose nullable columns.
 *
 * Both null means unlimited: every version of the package, which is how every assignment
 * behaves today and how one still created without either bound must go on behaving. A
 * single bound narrows from that side only; the other stays open.
 */
final readonly class VersionBounds
{
    public function __construct(
        public ?string $min,
        public ?string $max,
    ) {}

    /** No lower bound and no upper bound — every version of the package. */
    public function isUnlimited(): bool
    {
        return $this->min === null && $this->max === null;
    }

    public static function unlimited(): self
    {
        return new self(null, null);
    }

    /** The two pivot columns, read as one value rather than two loose nullables. */
    public static function fromPivot(?string $min, ?string $max): self
    {
        return new self($min, $max);
    }
}
