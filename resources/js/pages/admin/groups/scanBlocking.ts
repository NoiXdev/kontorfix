/**
 * The scan-blocking settings screen's own logic, pulled out of `ScanBlocking.vue` for the
 * reason `packageAssignment.ts` gives: this repo's vitest runs in a `node` environment with no
 * component-mounting harness, so logic left inside a `.vue` file is logic nothing verifies.
 */

import type { Severity } from '@/lib/severity';

/** One artifact `ScanBlockGuard::preview()` names — see that class for the shape's origin. */
export interface PreviewArtifact {
    package: string | null;
    digest: string;
    tags: string[];
    vulnerability_id: string;
    severity: Severity;
    /**
     * The server's own German label for `severity` (`VulnerabilitySeverity::label()`),
     * rendered as-is rather than re-derived from `severity.ts`'s `severityLabel()`: that
     * function is a client-side PRESENTATION mapping of the five known values, kept general
     * for Task 7's findings component, and re-deriving from it here would be a second,
     * independent statement of a label the server already computed and sent.
     */
    severity_label: string;
    blocked: boolean;
    /** `YYYY-MM-DD`, no time of day — `ScanBlockGuard::preview()` emits it with `toDateString()`. */
    blocks_at: string;
}

/** `ScanBlockGuard::preview()`'s whole answer, as the endpoint sends it. */
export interface ScanPreview {
    blocking_now: number;
    blocking_later: number;
    artifacts: PreviewArtifact[];
}

/**
 * Parses the scan-preview endpoint's JSON body into the typed shape above, tolerant of a
 * malformed or empty response — the same defensive parsing `postRetentionPreview()` applies
 * to its own endpoint's body, so a response that fails to match either shape degrades to
 * "nothing would be blocked" rather than throwing past the caller's own error handling.
 */
export function parseScanPreview(record: Record<string, unknown>): ScanPreview {
    return {
        blocking_now: typeof record.blocking_now === 'number' ? record.blocking_now : 0,
        blocking_later: typeof record.blocking_later === 'number' ? record.blocking_later : 0,
        artifacts: Array.isArray(record.artifacts) ? (record.artifacts as PreviewArtifact[]) : [],
    };
}

/**
 * The request body for both the preview and the save endpoint.
 *
 * A BLANK field is passed through as `null`, never `Number()`'d: `Number('')` is `0`, not
 * `NaN`, so a grace-days field the operator has cleared (the Input component's v-model always
 * yields a string; an emptied field yields `''`) would otherwise silently become `0` — the
 * STRICTEST setting this control can express, "block as soon as a finding is recorded" — and
 * a customer-visible pull outage produced by someone who believed they had emptied a field is
 * not a harmless default. `null` falls into the FormRequest's `required` rule instead, the
 * same way a genuinely missing field always has, so the operator sees "Die Schonfrist ist
 * erforderlich." rather than having their input silently reinterpreted.
 *
 * Everything else IS converted with `Number()`: a value the operator actually typed, however
 * it arrived (a real `number` from the initial page load, or a numeric string from the
 * Input's v-model), is sent as a number rather than a string the server would have to parse
 * itself. `0` stays reachable — typing it is not blank.
 */
export function scanBlockingPayload(severity: Severity | null, graceDays: number | string): Record<string, unknown> {
    const isBlank = typeof graceDays === 'string' && graceDays.trim() === '';

    return { scan_block_severity: severity, scan_block_grace_days: isBlank ? null : Number(graceDays) };
}
