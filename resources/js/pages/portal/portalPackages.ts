/**
 * How the portal's landing page describes a package: which markers a row carries, what a
 * lapsed assignment means for the reader, and how a single registry that stopped serving is
 * marked at the link the customer would click.
 *
 * Extracted from `Packages.vue` for the reason `admin/groups/packageAssignment.ts` gives:
 * there is no component runner on this frontend, so logic left inside a `.vue` file is logic
 * nothing verifies — and the wording below is a factual claim about what the customer's build
 * receives, where being wrong produces an unexplained outage rather than a cosmetic defect.
 *
 * TWO ANSWERS, NOT ONE, and they can differ on the same package. `PortalPackages` decides
 * `in_force` per registry and DERIVES the row's flag from the entries (in force in at least
 * one of them), so a package can be live in one of a customer's registries and lapsed in
 * another. The row flag answers "is this package usable at all"; the per-registry marker
 * answers "does THIS registry still serve it". Rendering only the row would tell a customer
 * everything is fine while one of their registries answers 404, which is the whole defect the
 * per-registry entry was introduced for.
 */
import { formatDay } from '@/pages/admin/groups/packageAssignment';

/**
 * The markers a row can carry. A union rather than plain strings: the page maps each one to a
 * component, and a typo would silently render nothing.
 *
 * `geteilt` is the word `SharedBadge` itself renders — the badge is a shared component used
 * across the console, so the page renders THAT component for this marker rather than a portal
 * copy of its styling. The word appears here only as the marker's name.
 */
export type PortalBadge = 'geteilt' | 'abgelaufen';

/** What `badgesFor` needs of a row, as Portal\PackageController::index() sends it. */
export interface PortalPackageRow {
    /** The package is owned by the operator organization and shared into this customer's registries. */
    shared: boolean;
    /**
     * Whether the package is usable at all — served by at least one of this customer's
     * registries. Decided by the server and deliberately NOT re-derived here from the
     * registry entries: a second statement of that rule in the browser could disagree with
     * the one the registry answers by.
     */
    in_force: boolean;
}

/** One registry's answer for this package, as the payload's `registries` entries carry it. */
export interface PortalRegistryEntry {
    in_force: boolean;
    /** `YYYY-MM-DD`, or null for an assignment with no end date. */
    available_until: string | null;
}

/**
 * The row's markers, in render order: what the package IS first, then what is wrong with it.
 *
 * An in-force own package gets nothing. A badge on the ordinary case is noise, and noise is
 * what makes the `abgelaufen` badge easy to miss on the row that has it.
 */
export function badgesFor(row: PortalPackageRow): PortalBadge[] {
    const badges: PortalBadge[] = [];

    if (row.shared) {
        badges.push('geteilt');
    }

    if (!row.in_force) {
        badges.push('abgelaufen');
    }

    return badges;
}

/**
 * What a fully lapsed package means for the reader.
 *
 * It names the 404 because that is the symptom the customer arrives with: the package is
 * listed, the build fails, and nothing so far said the two are the same fact. And it points
 * at the operator rather than at anything the reader could do — only the operator can extend
 * an assignment, so an instruction the customer cannot follow would be worse than none.
 *
 * The console's operator-facing note (`availabilityNote`) also explains that the name stays
 * blocked and is not passed to the upstream. That is deliberately absent here: it is an
 * answer to "why does my own fallback not take over", a question about the operator's
 * configuration that the customer neither asks nor can act on.
 */
export function lapsedNote(): string {
    return (
        'Diese Zuweisung ist abgelaufen: Die Registry liefert das Paket nicht mehr aus, ' +
        'Builds erhalten dafür einen 404. Wenden Sie sich an den Betreiber, wenn Sie es ' +
        'weiter benötigen.'
    );
}

/**
 * The marker for ONE registry that no longer serves this package — null where it still does.
 *
 * Rendered at the registry link, because that is where the customer would click through to a
 * registry that answers 404, and because a row that is in force elsewhere carries no badge of
 * its own to warn them.
 *
 * A lapsed entry need not have a date: `in_force` is the server's answer from
 * `RegistryAccessService`, which withholds a package both past its `available_until` AND when
 * it is neither owned by the registry's organization nor shared any more. The second case has
 * no date at all, and the registry answers 404 in exactly the same way, so it is marked in
 * exactly the same way — just without a day.
 *
 * `formatDay` from the console's assignment module rather than a second formatter: the day
 * form (`31.12.2026`, string surgery so no timezone can shift it) is stated once for the whole
 * frontend, and an operator and their customer reading two spellings of one date is precisely
 * the kind of disagreement this portal exists to end.
 */
export function registryMarker(entry: PortalRegistryEntry): string | null {
    if (entry.in_force) {
        return null;
    }

    return entry.available_until === null ? 'abgelaufen' : `abgelaufen am ${formatDay(entry.available_until)}`;
}
