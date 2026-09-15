<?php

namespace App\Services\Scanner;

/**
 * A completed scan's findings.
 *
 * Named for the ADAPTER to keep it distinct from the `OciScanReport` model: this one is
 * what came off the wire, that one is what we decided to keep. The two are deliberately not
 * the same object — the model carries `first_seen_at`, which no adapter can know.
 */
final class AdapterScanReport
{
    /**
     * @param  list<ScannedVulnerability>  $findings
     */
    public function __construct(public readonly array $findings) {}
}
