/**
 * The package page's "Freigaben" tab — the operator-facing view over one package's
 * cross-registry assignments (who has it, until when, under which version bounds).
 *
 * Extracted from `Freigaben.vue` for the reason `../../groups/packageAssignment.ts` gives:
 * there is no component runner on this frontend, so logic left inside a `.vue` file is
 * logic nothing verifies. The bounds preview below is exactly that kind of logic — it
 * tells an operator, before they save, whether the window they just built admits any
 * version at all, and getting that silently wrong ships a licence nobody can use.
 */

/** One row of the "Freigaben" tab, as `Admin\PackageController::show()` sends it. */
export interface AssignmentRow {
    organization_id: string;
    organization_name: string;
    group_id: string;
    group_name: string;
    /** Inclusive lower bound, or null for "no lower bound". */
    version_min: string | null;
    /** Exclusive upper bound, or null for "no upper bound". */
    version_max: string | null;
    /** `YYYY-MM-DD`, or null for an open-ended assignment. */
    available_until: string | null;
    available_until_iso: string | null;
    /** Decided by the server (`Group::assignedPackages()`'s predicate) — never re-derived here. */
    in_force: boolean;
}

/** One registry the "Registry freigeben" picker may offer. */
export interface AssignableGroup {
    id: string;
    name: string;
    organization_name: string;
}

/** `2026-12-31` → `31.12.2026`. String surgery, so no timezone can shift the day. */
export function formatDay(day: string): string {
    const parts = day.slice(0, 10).split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : day;
}

/** The "verfügbar bis" cell: a formatted day, or "unbegrenzt" for an open-ended assignment. */
export function availableUntilLabel(availableUntil: string | null): string {
    return availableUntil === null ? 'unbegrenzt' : `bis ${formatDay(availableUntil)}`;
}

/**
 * The aktiv/abgelaufen badge. Reads the server's own `in_force` flag rather than comparing
 * `available_until` against "now" here — the same reasoning `../../groups/packageAssignment`
 * gives for the registry-side table: a second statement of the expiry predicate in the
 * browser could disagree with the registry at the boundary, and disagreeing about exactly
 * this is the defect that column exists to prevent.
 */
export function statusLabel(inForce: boolean): 'aktiv' | 'abgelaufen' {
    return inForce ? 'aktiv' : 'abgelaufen';
}

/** The bounds cell: "ab 2.0 < 3.0", "ab 2.0", "< 3.0", or "alle" for no bounds at all. */
export function boundsLabel(min: string | null, max: string | null): string {
    if (min === null && max === null) {
        return 'alle';
    }
    if (min !== null && max !== null) {
        return `ab ${min} < ${max}`;
    }

    return min !== null ? `ab ${min}` : `< ${max}`;
}

/** One row grouped by customer organization, for the "grouped by customer" layout. */
export interface OrganizationGroup {
    organization_id: string;
    organization_name: string;
    rows: AssignmentRow[];
}

/**
 * Groups the flat, already-sorted `assignments` payload by customer organization,
 * preserving the server's own order (organization name, then registry name) rather than
 * re-sorting — a second sort here could disagree with the server's over collation, and
 * the whole point of grouping is to render the rows in the order they arrived.
 */
export function groupByOrganization(assignments: AssignmentRow[]): OrganizationGroup[] {
    const groups: OrganizationGroup[] = [];
    const index = new Map<string, OrganizationGroup>();

    for (const row of assignments) {
        let group = index.get(row.organization_id);
        if (!group) {
            group = { organization_id: row.organization_id, organization_name: row.organization_name, rows: [] };
            index.set(row.organization_id, group);
            groups.push(group);
        }
        group.rows.push(row);
    }

    return groups;
}

/** The three ways the editor dialog lets an operator state a package's version bounds. */
export type BoundsMode = 'all' | 'major' | 'custom';

/** `'2'` → `{ min: '2.0', max: '3.0' }` — what the "Nur eine Hauptversion" dropdown writes. */
export function majorLineBounds(major: string): { min: string; max: string } {
    const n = Number(major);

    return { min: `${n}.0`, max: `${n + 1}.0` };
}

/**
 * The inverse of {@see majorLineBounds}: whether a STORED bounds pair is exactly what
 * picking one major line would have written, so the editor can re-open on the matching
 * radio option instead of always defaulting to "Eigener Bereich" for a row it did not
 * create in this session. Anything that is not exactly `X.0`/`(X+1).0` — including a
 * hand-typed `2.0.0`/`3.0.0`, which admits the same versions but is not the SAME string
 * pair a fresh pick of major line "2" would write — returns null rather than guessing.
 */
export function detectMajorLine(min: string | null, max: string | null): string | null {
    if (min === null || max === null) {
        return null;
    }

    const match = /^(\d+)\.0$/.exec(min);
    if (!match) {
        return null;
    }

    const major = Number(match[1]);

    return max === `${major + 1}.0` ? String(major) : null;
}

/**
 * The effective bounds the dialog is about to submit, for whichever of the three modes is
 * selected. An unselected major line (the dropdown's placeholder) falls back to unlimited
 * rather than submitting a nonsensical `NaN.0` pair.
 */
export function effectiveBounds(mode: BoundsMode, major: string, customMin: string, customMax: string): { min: string | null; max: string | null } {
    if (mode === 'major') {
        return major === '' ? { min: null, max: null } : majorLineBounds(major);
    }
    if (mode === 'custom') {
        return {
            min: customMin.trim() === '' ? null : customMin.trim(),
            max: customMax.trim() === '' ? null : customMax.trim(),
        };
    }

    return { min: null, max: null };
}

/**
 * A version comparator good enough for the editor's PREVIEW line only — never for anything
 * the server would need to agree with. It splits each version on runs of digits and compares
 * those numeric runs left to right, which reads plain semver and PEP 440 release segments
 * correctly but is not a real parser for either (pre/post/dev segments, epochs, and any
 * non-numeric run are simply ignored). That is an intentional, documented limitation: the
 * bounds actually being saved are validated by `AssignmentWriter` at save time and enforced
 * by `VersionEntitlement` at serve time, both of which use the real per-ecosystem
 * comparators — this only has to warn an operator often enough to be worth having, not be
 * the authority on what the registry will do.
 */
function versionRunNumbers(version: string): number[] {
    return (version.match(/\d+/g) ?? []).map(Number);
}

function compareVersionsForPreview(a: string, b: string): number {
    const left = versionRunNumbers(a);
    const right = versionRunNumbers(b);
    const length = Math.max(left.length, right.length);

    for (let i = 0; i < length; i++) {
        const diff = (left[i] ?? 0) - (right[i] ?? 0);
        if (diff !== 0) {
            return diff;
        }
    }

    return 0;
}

/** Whether `version` falls within `[min, max)` under the preview comparator above. */
export function admitsForPreview(version: string, min: string | null, max: string | null): boolean {
    if (min !== null && compareVersionsForPreview(version, min) < 0) {
        return false;
    }

    return !(max !== null && compareVersionsForPreview(version, max) >= 0);
}

/**
 * The highest version these bounds admit, out of `versions` in ANY order — or null if the
 * bounds admit none of them.
 *
 * Deliberately does not trust the caller to hand this pre-sorted newest-first, even though
 * the Composer/npm `versions` prop already is (`VersionOrder::sort()` on the server, the
 * same order `versions[0]` relies on elsewhere on this page): Python's dist list is ordered
 * by UPLOAD time, not by version, since a re-upload of an older release is a real event on
 * that tab. Reducing over every admitted version rather than taking the first one keeps
 * this correct for both without asking the caller to know which is which.
 */
export function highestAdmittedForPreview(versions: string[], min: string | null, max: string | null): string | null {
    const admitted = versions.filter((v) => admitsForPreview(v, min, max));

    return admitted.length === 0 ? null : admitted.reduce((best, v) => (compareVersionsForPreview(v, best) > 0 ? v : best));
}

/**
 * The editor's preview line: names the highest admitted version, or — the one thing this
 * feature must never let an operator save without seeing — warns that the bounds as
 * entered exclude every version of the package.
 */
export function boundsPreview(versions: string[], min: string | null, max: string | null): string {
    if (versions.length === 0) {
        return 'Für dieses Paket liegen keine Versionen vor.';
    }

    const highest = highestAdmittedForPreview(versions, min, max);

    return highest === null ? 'Diese Eingrenzung schließt alle Versionen aus.' : `Höchste zulässige Version: ${highest}`;
}
