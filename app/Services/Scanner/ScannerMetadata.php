<?php

namespace App\Services\Scanner;

/** Who produced a verdict. Persisted onto every report so a scanner swap stays auditable. */
final class ScannerMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $version,
    ) {}
}
