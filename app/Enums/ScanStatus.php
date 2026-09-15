<?php

namespace App\Enums;

/**
 * Whether a manifest has a vulnerability verdict, and if not, why.
 *
 * Three states rather than a boolean because "nobody has looked yet" and "we looked and it
 * went wrong" are different statements to an operator: the first waits, the second is
 * acted on. Rendering them identically is how a broken scanner reads as a clean registry.
 *
 * `Failed` means "no verdict has EVER been reached for this manifest". A failed rescan of a
 * manifest that already has one does not come back here — it records `error`/`failed_at`
 * beside the good report and leaves this alone. See ScanReportWriter::recordFailure().
 */
enum ScanStatus: string
{
    /** Queued, or in flight. No findings yet, and none implied. */
    case Pending = 'pending';

    /** A scan completed. The findings on this report are that scan's answer. */
    case Ok = 'ok';

    /** Every attempt so far has failed. `error` says how. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Prüfung läuft',
            self::Ok => 'Geprüft',
            self::Failed => 'Prüfung fehlgeschlagen',
        };
    }
}
