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

/** A run of three or more entries folded into one expandable row. */
export interface ActivityBurst {
    type: 'burst';
    key: string;
    entries: ActivityEntry[];
    /** "N erfolgreich, M fehlgeschlagen", or null when that cannot be read off the entries. */
    outcome: string | null;
}

/** An entry too rare — alone or in a pair — to be worth folding. */
export interface ActivitySingle {
    type: 'single';
    entry: ActivityEntry;
}

export type ActivityRow = ActivitySingle | ActivityBurst;

/** Fewer than this many matching entries render individually rather than folding. */
const MIN_BURST_SIZE = 3;

/**
 * The bucket an entry belongs to for burst folding, or `null` when it has no timestamp to
 * bucket by — such an entry never folds with anything, rather than every timestamp-less
 * entry silently folding into one burst with every other.
 *
 * Minute + log_name + event + subject_type + causer, deliberately without the individual
 * subject: that omission is what turns twelve "package updated" rows about twelve different
 * packages into one "12 Pakete aktualisiert" row instead of leaving them all separate.
 */
function burstKey(entry: ActivityEntry): string | null {
    if (!entry.created_at_exact || entry.created_at_exact.length < 16) {
        return null;
    }

    const minute = entry.created_at_exact.slice(0, 16);

    return [minute, entry.log_name ?? '', entry.event ?? '', entry.subject_type ?? '', entry.causer ?? ''].join('|');
}

/**
 * Folds runs of three or more entries that share a minute, log, event, subject type and
 * causer into one expandable burst; everything else — including a burst of only two — stays
 * an individual row.
 *
 * Only applied when `chronological` is true. The global activity page can be sorted by
 * `description` or `log_name` (see `useActivityQuery.ts` / `ActivityController::SORTABLE`),
 * and under those orderings time-adjacent rows carry no relationship to each other — folding
 * them would present an accidental adjacency as a burst that never happened. The per-subject
 * tabs are always chronological, so they always pass `true`.
 *
 * The order of `entries` is preserved: a burst appears where its first entry did, in the
 * order the caller gave them, exactly as `groupByDay` treats order as the caller's decision
 * rather than something to re-sort.
 */
export function groupBursts(entries: ActivityEntry[], chronological: boolean): ActivityRow[] {
    if (!chronological) {
        return entries.map((entry) => ({ type: 'single', entry }));
    }

    const buckets = new Map<string, ActivityEntry[]>();

    for (const entry of entries) {
        const key = burstKey(entry);

        if (key === null) {
            continue;
        }

        const bucket = buckets.get(key);

        if (bucket) {
            bucket.push(entry);
        } else {
            buckets.set(key, [entry]);
        }
    }

    const rows: ActivityRow[] = [];
    const emitted = new Set<string>();

    for (const entry of entries) {
        const key = burstKey(entry);
        const bucket = key === null ? null : buckets.get(key)!;

        if (!bucket || bucket.length < MIN_BURST_SIZE) {
            rows.push({ type: 'single', entry });
            continue;
        }

        if (emitted.has(key as string)) {
            continue;
        }

        emitted.add(key as string);
        rows.push({ type: 'burst', key: key as string, entries: bucket, outcome: burstOutcome(bucket) });
    }

    return rows;
}

/** A plain object, or null for anything else — `changes` is shaped per key by whatever wrote it. */
function asRecord(value: unknown): Record<string, unknown> | null {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    return value as Record<string, unknown>;
}

/**
 * "N erfolgreich, M fehlgeschlagen" for a burst, derived from each entry's `sync_status`
 * change (Spatie writes the bare event name into `description`, so the entries carry no
 * text of their own to summarise — this is the only signal available).
 *
 * Returns null the moment one entry's outcome cannot be read as a plain success or failure —
 * missing, mid-flight (`pending`/`syncing`), or simply not a sync event at all. A burst whose
 * outcome cannot be derived unambiguously shows only its count; guessing a number here would
 * misreport the log it exists to be an honest record of.
 */
export function burstOutcome(entries: ActivityEntry[]): string | null {
    let succeeded = 0;
    let failed = 0;

    for (const entry of entries) {
        const status = asRecord(entry.changes?.attributes)?.sync_status;

        if (status === 'synced') {
            succeeded += 1;
        } else if (status === 'failed') {
            failed += 1;
        } else {
            return null;
        }
    }

    if (failed === 0) {
        return `${succeeded} erfolgreich`;
    }

    if (succeeded === 0) {
        return `${failed} fehlgeschlagen`;
    }

    return `${succeeded} erfolgreich, ${failed} fehlgeschlagen`;
}
