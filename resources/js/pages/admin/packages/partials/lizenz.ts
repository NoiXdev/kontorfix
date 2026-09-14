/**
 * The organization-wide package licence — the ceiling every registry assignment of the
 * same organization is served through (see `App\Services\Licence\VersionEntitlement`).
 * `effectiveWindow()` is the ecosystem-agnostic display half of that rule: what a given
 * registry row actually serves once its organization's licence, if any, is applied.
 */

export type Window = { min: string | null; max: string | null };
export type Licence = { min: string | null; max: string | null; expired: boolean };
export type Effective = Window & { narrowedByLicence: boolean; empty: boolean };

/**
 * What a registry assignment actually serves once the organization's licence is applied.
 *
 * The display must not show the raw row: with a licence in force the served window is the
 * intersection, so a row rendering only its own numbers would misreport. Ordering here is
 * string-free — the server sends both sides already validated, and the UI only needs to
 * know WHICH side won, which the backend marks by sending the narrowed value.
 */
export function effectiveWindow(row: Window, licence: Licence | null): Effective {
    if (!licence) {
        return { ...row, narrowedByLicence: false, empty: false };
    }

    if (licence.expired) {
        return { min: row.min, max: row.max, narrowedByLicence: true, empty: true };
    }

    const min = pick(row.min, licence.min, 'max');
    const max = pick(row.max, licence.max, 'min');

    return {
        min,
        max,
        narrowedByLicence: min !== row.min || max !== row.max,
        empty: min !== null && max !== null && compare(min, max) > 0,
    };
}

function pick(a: string | null, b: string | null, take: 'min' | 'max'): string | null {
    if (a === null) return b;
    if (b === null) return a;
    const cmp = compare(a, b);
    return take === 'max' ? (cmp >= 0 ? a : b) : (cmp <= 0 ? a : b);
}

/** Numeric-segment comparison — enough for display; the binding decision is the server's. */
function compare(a: string, b: string): number {
    const pa = a.split(/[.\-+]/);
    const pb = b.split(/[.\-+]/);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const na = Number.parseInt(pa[i] ?? '0', 10) || 0;
        const nb = Number.parseInt(pb[i] ?? '0', 10) || 0;
        if (na !== nb) return na - nb;
    }
    return 0;
}
