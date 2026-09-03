/**
 * How a registry's package assignment is described: whether it is in force, until when, and
 * — the part that is not obvious — what an expiry actually does.
 *
 * Extracted from `Show.vue` for the reason `lib/activityGroups.ts` gives: there is no
 * component runner on this frontend, so logic left inside a `.vue` file is logic nothing
 * verifies. Here that matters more than usual, because the wording below is a factual claim
 * about the registry's behaviour and getting it wrong produces silent, unexplained customer
 * outages rather than a cosmetic defect.
 *
 * WHAT AN EXPIRY DOES, and why the copy insists on it. `group_package.available_until` was
 * only ever read before this dialog existed. Two rules, deliberately disagreeing, now meet
 * on it:
 *
 *  - Resolution stops at the date. `Group::assignedPackages()` filters the row out, so the
 *    registry serves the package no more — correct, the customer is no longer entitled to
 *    the artifacts.
 *  - The upstream fallthrough does NOT reopen. `packageExistsLocally()` and its npm/PyPI
 *    counterparts ask whether this registry has EVER served the name, not whether it serves
 *    it today, so a lapsed assignment keeps suppressing Packagist/npmjs/PyPI (spec §4, as
 *    amended during execution).
 *
 * The result is a hard 404 for the customer's build, permanently, until somebody detaches
 * the assignment. That is the point: if expiry failed open, the trigger for a public
 * package silently taking a private name's place would be an operator's calendar date
 * rather than any deliberate act. Detaching stays the clean way to release a name.
 *
 * So an operator setting a date has to be told it is not "afterwards it falls away", and an
 * operator looking at a lapsed row has to be told what their customer is seeing right now.
 * Both texts live here.
 */

/** One row of the registry's package table, as Admin\GroupController::show() sends it. */
export interface AssignedPackage {
    id: string;
    name: string;
    type: string;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    shared: boolean;
    /** `YYYY-MM-DD`, or null for an open-ended assignment. */
    available_until: string | null;
    /**
     * Whether the registry actually serves this assignment right now.
     *
     * Decided by the server from `Group::assignedPackages()` — the single statement of the
     * expiry predicate that resolution itself uses — and deliberately NOT re-derived here
     * from `available_until`. A second statement of it in the browser could disagree with
     * the registry over a boundary case, and the whole reason this column is surfaced is
     * that the console used to disagree with the registry about exactly this.
     */
    in_force: boolean;
}

export type AvailabilityState = 'permanent' | 'limited' | 'lapsed';

export interface Availability {
    state: AvailabilityState;
    /** The day itself, `DD.MM.YYYY`, or null when the assignment is open-ended. */
    day: string | null;
}

/** `2026-12-31` → `31.12.2026`. String surgery, so no timezone can shift the day. */
export function formatDay(day: string): string {
    const parts = day.slice(0, 10).split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : day;
}

/**
 * An assignment with no date is open-ended; one with a date is either still in force or has
 * lapsed, and only the server may say which.
 */
export function availabilityOf(assignment: Pick<AssignedPackage, 'available_until' | 'in_force'>): Availability {
    if (assignment.available_until === null) {
        return { state: 'permanent', day: null };
    }

    const day = formatDay(assignment.available_until);

    return { state: assignment.in_force ? 'limited' : 'lapsed', day };
}

/** The short form for the table cell. */
export function availabilityLabel(availability: Availability): string {
    switch (availability.state) {
        case 'permanent':
            return 'unbefristet';
        case 'limited':
            return `bis ${availability.day}`;
        case 'lapsed':
            return `abgelaufen am ${availability.day}`;
    }
}

/**
 * What this assignment means for the customer, in full — null where there is nothing
 * non-obvious to say.
 *
 * The lapsed text has to answer the question the operator is actually asking when they read
 * it ("why is the build failing when the package is right there in the list?"), so it names
 * the 404 and the two actions that end it.
 *
 * "Upstream" throughout, which is what this console calls the thing everywhere else — the
 * registry has an Upstreams tab of its own, and the URL there is configurable, so naming
 * Packagist as though it were the destination would be wrong as often as it was right. It
 * also matters that the suppression happens HERE and not at the upstream: the name is not
 * blocked at Packagist, the request simply never reaches it.
 */
export function availabilityNote(availability: Availability): string | null {
    switch (availability.state) {
        case 'permanent':
            return null;
        case 'limited':
            return (
                `Diese Registry liefert das Paket bis einschließlich ${availability.day} aus. ` +
                'Danach nicht mehr — der Name bleibt aber weiterhin belegt: Anfragen werden nicht an den ' +
                'Upstream weitergereicht, sondern enden mit 404, bis die Zuweisung entfernt wird.'
            );
        case 'lapsed':
            return (
                `Am ${availability.day} abgelaufen: Diese Registry liefert das Paket nicht mehr aus. ` +
                'Builds, die es anfordern, erhalten einen 404 — die Registry reicht den Namen weiterhin ' +
                'nicht an den Upstream weiter. Verlängern Sie die Zuweisung, oder entfernen Sie sie, um den ' +
                'Namen freizugeben.'
            );
    }
}

/**
 * The note under the date field, read before the operator commits to a date.
 *
 * Deliberately says what does NOT happen after the date as well as what does: "verfügbar
 * bis 31.12." reads as "afterwards it falls away", and that is the one reading this feature
 * does not implement.
 *
 * It also names the past-date case, because that is the only way to say "stop delivering
 * now, and keep the name blocked". Detaching stops delivery too, but releases the name to
 * the upstream — a different, less safe outcome, and the one spec §4 warns about.
 */
export const EXPIRY_CONSEQUENCE =
    'Nach diesem Tag liefert die Registry das Paket nicht mehr aus. Der Name bleibt dabei belegt: Anfragen ' +
    'werden nicht an den Upstream (z. B. Packagist) weitergereicht, sondern enden mit 404 — so lange, bis ' +
    'die Zuweisung entfernt wird. Das ist Absicht: Es verhindert, dass ein fremdes Paket still an die Stelle ' +
    'des bisherigen tritt. Ohne Datum bleibt die Zuweisung unbefristet.';

/**
 * Whether the day the operator has picked is already over, so saving ends delivery at once
 * rather than at some point in the future.
 *
 * Both arguments are `YYYY-MM-DD`, which sorts lexicographically, so this is a plain string
 * comparison. `today` comes from the SERVER (the page payload), never from the browser
 * clock: the application runs in UTC and a browser west of it spends several hours on the
 * previous day, which would have this answer disagree with what the stored value does.
 *
 * Strictly earlier, not earlier-or-equal: a date of today stores the END of today, so the
 * assignment stays in force for the rest of the day and nothing happens immediately.
 */
export function endsImmediately(day: string, today: string): boolean {
    return day !== '' && day < today;
}

/**
 * The warning for that case — shown while the operator is still choosing, because a date in
 * the past is the one entry whose effect is instantaneous and irreversible without a second
 * edit.
 *
 * It says what makes this different from detaching, since those are the two ways to stop
 * delivering and only one of them keeps the name safe.
 */
export const IMMEDIATE_WITHDRAWAL_NOTE =
    'Dieses Datum liegt in der Vergangenheit: Die Registry stellt die Auslieferung sofort ein. Der Name ' +
    'bleibt weiterhin belegt und wird nicht an den Upstream weitergereicht — das ist der sichere Weg, eine ' +
    'Freigabe zurückzunehmen. Erst das Entfernen der Zuweisung gibt den Namen frei.';
