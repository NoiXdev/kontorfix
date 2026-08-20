/**
 * Groups activity log entries by calendar day for the timeline view.
 *
 * Extracted from the component so the boundary cases can be tested: there is no frontend
 * runner for components, and the in-app browser cannot load this application, so logic left
 * inside a `.vue` file is logic nothing verifies.
 */

/** The presented row, as the controller sends it. */
export interface ActivityEntry {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_label: string | null;
    causer: string | null;
    changes: Record<string, unknown>;
    created_at: string | null;
    created_at_exact: string | null;
}

export interface ActivityGroup {
    key: string;
    label: string;
    entries: ActivityEntry[];
}

const NO_TIMESTAMP_KEY = 'no-timestamp';

const dateFormatter = new Intl.DateTimeFormat('de-DE', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

/** Local calendar date as `YYYY-MM-DD`, ignoring time of day. */
function dayKey(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function labelFor(date: Date, now: Date): string {
    if (dayKey(date) === dayKey(now)) {
        return 'Heute';
    }

    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);

    if (dayKey(date) === dayKey(yesterday)) {
        return 'Gestern';
    }

    return dateFormatter.format(date);
}

/**
 * Group entries by the local calendar date of `created_at_exact`.
 *
 * The order within and across groups is exactly the order `entries` was given in — the
 * controller decides that order (`admin/activity` is sortable by column), so re-sorting here
 * would silently override a sort the reader chose.
 *
 * An entry with no timestamp gets its own group rather than being dropped: the log is an
 * audit trail, and silently hiding a row from it is the wrong failure mode.
 */
export function groupByDay(entries: ActivityEntry[], now: Date = new Date()): ActivityGroup[] {
    const groups: ActivityGroup[] = [];
    const groupsByKey = new Map<string, ActivityGroup>();

    for (const entry of entries) {
        let key: string;
        let label: string;

        if (!entry.created_at_exact) {
            key = NO_TIMESTAMP_KEY;
            label = 'Ohne Zeitstempel';
        } else {
            const parsed = new Date(entry.created_at_exact.replace(' ', 'T'));
            key = dayKey(parsed);
            label = labelFor(parsed, now);
        }

        let group = groupsByKey.get(key);

        if (!group) {
            group = { key, label, entries: [] };
            groupsByKey.set(key, group);
            groups.push(group);
        }

        group.entries.push(entry);
    }

    return groups;
}

/**
 * The `HH:MM` an entry was logged at, or `—` when it carries no timestamp.
 *
 * Read straight out of the `Y-m-d H:i:s` string rather than through `Date`, so the time
 * shown is the one `groupByDay` grouped on: that reading treats the value as local
 * wall-clock time, and the string carries no offset for either to reinterpret. Going
 * through `Date` here would put an entry under `Heute` at a time from another day the
 * moment the two readings disagree.
 */
export function timeOfDay(exact: string | null | undefined): string {
    if (!exact || exact.length < 16) {
        return '—';
    }

    return exact.slice(11, 16);
}
