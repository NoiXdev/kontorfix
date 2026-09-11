<?php

namespace App\Support\Licence;

/**
 * A licence's full set of version windows — today always a single `VersionBounds` read off
 * one assignment row, but a list rather than a lone value so a later licence made of several
 * windows (e.g. stacked assignments) is additive, not a reshape.
 */
final readonly class VersionWindows
{
    /** @param list<VersionBounds> $windows */
    public function __construct(
        public array $windows,
    ) {}

    /**
     * Unlimited if there are no windows to restrict by, or if any single window already
     * grants every version — one open window makes the others moot.
     */
    public function isUnlimited(): bool
    {
        if ($this->windows === []) {
            return true;
        }

        foreach ($this->windows as $window) {
            if ($window->isUnlimited()) {
                return true;
            }
        }

        return false;
    }

    public static function unlimited(): self
    {
        return new self([]);
    }
}
