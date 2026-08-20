<?php

namespace App\Support;

use Carbon\CarbonInterface;

final readonly class DigestLine
{
    public function __construct(
        public string $type,
        public string $subjectLabel,
        public int $count,
        public string $latestSummary,
        public CarbonInterface $latestAt,
    ) {}
}
