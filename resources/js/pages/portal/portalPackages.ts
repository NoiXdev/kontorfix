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
 * The customer's reading of the `geteilt` badge, passed to `SharedBadge` in place of its
 * operator-facing default ("kann Registries anderer Organisationen zugewiesen werden" is a
 * capability the customer does not have).
 *
 * It says only that the operator provides the package, and deliberately NOT that it was
 * released to this organization. `shared` is an organization-agnostic boolean on the package:
 * nothing about it names a recipient, and what actually brings the package into this portal is
 * a registry assignment. In this console "Freigabe" IS the `shared` marking and never an
 * assignment — `packageAssignment.ts` keeps those two apart on purpose — so calling the
 * assignment a Freigabe here would undo that distinction on the page that most needs it.
 *
 * A constant in this module rather than a literal in the SFC: it was the one German string on
 * this page that nothing could test, which is the rule this module exists to enforce.
 */
export const SHARED_BADGE_TITLE = 'Vom Betreiber bereitgestellt.';

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

/** What the partial-lapse note needs of an entry: which registry, and whether it still serves. */
export interface PortalRegistryName {
    name: string;
    in_force: boolean;
}

/**
 * A German enumeration: `A`, `A und B`, `A, B und C`.
 *
 * Not `Intl.ListFormat`: it is locale-driven and this sentence is German whatever locale the
 * reader's browser reports, and it would put a serial comma nowhere German wants one.
 */
function joinNames(names: string[]): string {
    if (names.length < 2) {
        return names.join('');
    }

    return `${names.slice(0, -1).join(', ')} und ${names[names.length - 1]}`;
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
 * "keiner Ihrer Registries", not "die Registry": this note renders only where the package is
 * served by NONE of them, which can be several, and it points at the per-registry markers for
 * which ones rather than naming a single date it does not have.
 *
 * "oben markiert", NOT "mit dem Ablaufdatum markiert". `registryMarker` renders a bare
 * `abgelaufen` with no day whenever `available_until` is null, so on a mixed set the promise
 * of a date is false for at least one of the very markers this sentence points at.
 *
 * The console's operator-facing note (`availabilityNote`) also explains that the name stays
 * blocked and is not passed to the upstream. That is deliberately absent here: it is an
 * answer to "why does my own fallback not take over", a question about the operator's
 * configuration that the customer neither asks nor can act on.
 */
export function lapsedNote(): string {
    return (
        'Dieses Paket wird von keiner Ihrer Registries mehr ausgeliefert: Builds erhalten ' +
        'dafür einen 404. Die betroffenen Registries sind oben markiert. Wenden Sie sich an ' +
        'den Betreiber, wenn Sie das Paket weiter benötigen.'
    );
}

/**
 * The consequence for a package that is still usable but has stopped being served by SOME of
 * the customer's registries — null where there is no such case.
 *
 * This is the row spec §3 exists for and the one every earlier shape got wrong. The package is
 * in force, so it carries no `abgelaufen` badge and no `lapsedNote()`; before this, the whole
 * of what the customer was told was a parenthesised date beside one registry name. The person
 * whose build resolves against exactly that registry — the one person for whom something is
 * broken — was the one person given no consequence at all.
 *
 * The registries are NAMED rather than counted. "Eine Ihrer Registries liefert dieses Paket
 * nicht mehr aus" is true and useless: the reader has to map it back to the entry that is
 * marked, and a customer with several registries cannot tell whether the one their CI uses is
 * among them. The name is the only part of the sentence they can act on.
 *
 * The CONSEQUENCE is number-free — "Builds, die dort auflösen", "Ihre anderen Registries" —
 * because the common case is one lapsed registry and an earlier wording said "diese Registries"
 * and "die übrigen Registries" about a single one. That is the singular defect of `lapsedNote()`
 * reflected: copy that assumes a count it does not have.
 *
 * The one place a count still shows is the article in front of the names, and German has no
 * form that covers both. `In der Registry legacy und archive` would be the same defect one word
 * later, so the article and the noun are chosen with the list and everything after them is not.
 *
 * The closing clause is number-free too, and had to become so: "Über Ihre anderen Registries"
 * is plural over a remainder that is exactly one whenever a customer has two registries and one
 * of them has lapsed — the common shape. "nur nicht mehr dort" counts nothing on either side.
 *
 * It is a guarantee, not a hope: `in_force` is DERIVED from these same entries, and the entries
 * are already filtered to the portal-visible registries the page renders. A row that reaches
 * this function therefore has at least one rendered registry that still serves the package.
 */
export function partlyLapsedNote(row: PortalPackageRow & { registries: PortalRegistryName[] }): string | null {
    // A row that is in force nowhere is `lapsedNote()`'s case, not this one. Stated here and
    // not in the template so that the two notes cannot both render, and cannot both be
    // suppressed, by a condition written twice.
    if (!row.in_force) {
        return null;
    }

    const lapsed = row.registries.filter((registry) => !registry.in_force).map((registry) => registry.name);

    if (lapsed.length === 0) {
        return null;
    }

    const where = lapsed.length === 1 ? `In der Registry ${lapsed[0]}` : `In den Registries ${joinNames(lapsed)}`;

    return (
        `${where} wird dieses Paket nicht mehr ausgeliefert. Builds, die dort auflösen, ` +
        'erhalten einen 404. Das Paket wird weiterhin ausgeliefert, nur nicht mehr dort.'
    );
}

/**
 * The one note this row carries, or null. The page asks this once — for the text and for the
 * separator that belongs to whichever row is last in the block — so the choice between the two
 * notes is made here rather than twice in a template.
 */
export function noteFor(row: PortalPackageRow & { registries: PortalRegistryName[] }): string | null {
    return row.in_force ? partlyLapsedNote(row) : lapsedNote();
}

/**
 * The marker for ONE registry that no longer serves this package — null where it still does.
 *
 * Rendered at the registry link, because that is where the customer would click through to a
 * registry that answers 404, and because a row that is in force elsewhere carries no badge of
 * its own to warn them.
 *
 * A lapsed entry need not have a date. `in_force` is the server's answer from
 * `RegistryAccessService`, which withholds a package both past its `available_until` AND when
 * it is neither owned by the registry's organization nor shared any more; the second case has
 * no date at all. The console cannot reach that state — `Admin\PackageController::shared()`
 * refuses to clear the flag while any foreign assignment exists, expired ones included — so it
 * arrives only from outside it: a package moved between organizations, a migration, a hand-run
 * UPDATE. The registry answers 404 there in exactly the same way, so the branch stays and marks
 * it in exactly the same way, just without a day. A `null` rendering nothing would leave that
 * link unmarked, which is the one outcome this marker exists to prevent.
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
