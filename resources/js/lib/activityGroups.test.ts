import { describe, expect, it } from 'vitest';
import { burstOutcome, groupByDay, groupBursts, timeOfDay, type ActivityEntry } from './activityGroups';

const at = (id: number, exact: string): ActivityEntry => ({
    id,
    log_name: 'package',
    event: 'updated',
    description: 'updated',
    subject_type: 'Package',
    // Null by default rather than a shared id: most fixtures here are not about per-subject
    // collapsing, and a null subject_id never merges with anything (see collapseBySubject),
    // so these entries behave exactly as they did before subject_id existed on the type.
    subject_id: null,
    subject_label: 'acme/demo',
    causer: 'Tim',
    changes: {},
    created_at: 'vor 1 Stunde',
    created_at_exact: exact,
});

const now = new Date('2026-08-19T12:00:00');

describe('groupByDay', () => {
    it('puts entries from the same day under one heading', () => {
        const groups = groupByDay([at(1, '2026-08-19 09:00:00'), at(2, '2026-08-19 17:30:00')], now);

        expect(groups).toHaveLength(1);
        expect(groups[0].entries.map((e) => e.id)).toEqual([1, 2]);
    });

    it('splits entries across a day boundary', () => {
        const groups = groupByDay([at(1, '2026-08-19 00:30:00'), at(2, '2026-08-18 23:30:00')], now);

        expect(groups).toHaveLength(2);
    });

    it('labels today and yesterday by name and older days by date', () => {
        const groups = groupByDay(
            [at(1, '2026-08-19 09:00:00'), at(2, '2026-08-18 09:00:00'), at(3, '2026-08-11 09:00:00')],
            now,
        );

        expect(groups.map((g) => g.label)).toEqual(['Heute', 'Gestern', '11. August 2026']);
    });

    it('keeps the order it was given rather than re-sorting', () => {
        // The controller decides the order — it is sortable, and by column. Re-sorting here
        // would silently override a descending sort the reader chose.
        const groups = groupByDay([at(2, '2026-08-19 17:00:00'), at(1, '2026-08-19 09:00:00')], now);

        expect(groups[0].entries.map((e) => e.id)).toEqual([2, 1]);
    });

    it('puts entries with no timestamp in their own group rather than dropping them', () => {
        const groups = groupByDay([{ ...at(1, ''), created_at_exact: null }], now);

        expect(groups).toHaveLength(1);
        expect(groups[0].entries).toHaveLength(1);
    });

    it('returns nothing for no entries', () => {
        expect(groupByDay([], now)).toEqual([]);
    });
});

describe('timeOfDay', () => {
    it('reads the hour and minute out of the exact timestamp', () => {
        expect(timeOfDay('2026-08-19 09:05:00')).toBe('09:05');
        expect(timeOfDay('2026-08-19 23:59:59')).toBe('23:59');
    });

    it('does not shift the time the way a Date parse would', () => {
        // The grouping reads the same string as local wall-clock time. If this went through
        // `Date` in a UTC-offset environment, an entry could sit under `Heute` showing a
        // time from the previous day.
        const exact = '2026-08-19 00:30:00';

        expect(timeOfDay(exact)).toBe('00:30');
        expect(groupByDay([{ ...at(1, exact) }], now)[0].label).toBe('Heute');
    });

    it('marks a missing or unusable timestamp rather than slicing garbage out of it', () => {
        expect(timeOfDay(null)).toBe('—');
        expect(timeOfDay(undefined)).toBe('—');
        expect(timeOfDay('')).toBe('—');
        expect(timeOfDay('2026-08-19')).toBe('—');
    });
});

/** A synced/failed `changes` payload, as `ActivityPresenter` shapes it for a `Package` update. */
const withSync = (id: number, exact: string, status: 'synced' | 'failed' | 'syncing'): ActivityEntry => ({
    ...at(id, exact),
    changes: { attributes: { sync_status: status }, old: { sync_status: 'syncing' } },
});

describe('groupBursts', () => {
    it('folds three or more entries sharing minute, log, event, subject type and causer into one burst', () => {
        const rows = groupBursts(
            [at(1, '2026-08-19 09:00:10'), at(2, '2026-08-19 09:00:20'), at(3, '2026-08-19 09:00:30')],
            true,
        );

        expect(rows).toHaveLength(1);
        expect(rows[0].type).toBe('burst');
        expect(rows[0].type === 'burst' && rows[0].entries.map((e) => e.id)).toEqual([1, 2, 3]);
        // None of these entries carry a subject_id, so per-subject collapsing must not
        // change anything: the count is still the raw number of entries.
        expect(rows[0].type === 'burst' && rows[0].count).toBe(3);
    });

    it('leaves two matching entries flat rather than folding them', () => {
        const rows = groupBursts([at(1, '2026-08-19 09:00:10'), at(2, '2026-08-19 09:00:20')], true);

        expect(rows).toHaveLength(2);
        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('splits a burst across a minute boundary', () => {
        const rows = groupBursts(
            [at(1, '2026-08-19 09:00:10'), at(2, '2026-08-19 09:00:50'), at(3, '2026-08-19 09:01:00')],
            true,
        );

        // Only two entries share the 09:00 minute — one short of a burst — so nothing folds.
        expect(rows).toHaveLength(3);
        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('splits a burst when a causer differs', () => {
        const rows = groupBursts(
            [
                at(1, '2026-08-19 09:00:10'),
                at(2, '2026-08-19 09:00:20'),
                { ...at(3, '2026-08-19 09:00:30'), causer: 'Alex' },
            ],
            true,
        );

        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('splits a burst when the event differs', () => {
        const rows = groupBursts(
            [
                at(1, '2026-08-19 09:00:10'),
                at(2, '2026-08-19 09:00:20'),
                { ...at(3, '2026-08-19 09:00:30'), event: 'created' },
            ],
            true,
        );

        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('splits a burst when the log_name differs', () => {
        const rows = groupBursts(
            [
                at(1, '2026-08-19 09:00:10'),
                at(2, '2026-08-19 09:00:20'),
                { ...at(3, '2026-08-19 09:00:30'), log_name: 'registry' },
            ],
            true,
        );

        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('splits a burst when the subject type differs', () => {
        const rows = groupBursts(
            [
                at(1, '2026-08-19 09:00:10'),
                at(2, '2026-08-19 09:00:20'),
                { ...at(3, '2026-08-19 09:00:30'), subject_type: 'Group' },
            ],
            true,
        );

        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('still folds when the subject type matches but individual subjects differ', () => {
        // Deliberately not keyed on the individual subject — this is what makes "12 packages
        // synced" one row instead of twelve, each about a different package.
        const rows = groupBursts(
            [
                { ...at(1, '2026-08-19 09:00:10'), subject_id: 'pkg-1', subject_label: 'acme/one' },
                { ...at(2, '2026-08-19 09:00:20'), subject_id: 'pkg-2', subject_label: 'acme/two' },
                { ...at(3, '2026-08-19 09:00:30'), subject_id: 'pkg-3', subject_label: 'acme/three' },
            ],
            true,
        );

        expect(rows).toHaveLength(1);
        expect(rows[0].type).toBe('burst');
        expect(rows[0].type === 'burst' && rows[0].count).toBe(3);
    });

    it('does not fold matching entries that are not contiguous', () => {
        // Entries 1, 2 and 3 share every field the key is built from, but an unrelated entry
        // (a different causer) sits between 1 and 2. Pulling 1 forward to join 2 and 3 would
        // reorder the timeline out from under a reader following it top to bottom, so the run
        // 2,3 is the only candidate — and at length 2 it is one short of a burst.
        const rows = groupBursts(
            [
                at(1, '2026-08-19 09:00:10'),
                { ...at(9, '2026-08-19 09:00:15'), causer: 'Alex' },
                at(2, '2026-08-19 09:00:20'),
                at(3, '2026-08-19 09:00:30'),
            ],
            true,
        );

        expect(rows).toHaveLength(4);
        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });

    it('preserves the given order rather than sorting the burst', () => {
        const rows = groupBursts(
            [at(3, '2026-08-19 09:00:30'), at(1, '2026-08-19 09:00:10'), at(2, '2026-08-19 09:00:20')],
            true,
        );

        expect(rows[0].type === 'burst' && rows[0].entries.map((e) => e.id)).toEqual([3, 1, 2]);
    });

    it('does not fold a non-chronological list', () => {
        // Under a sort by description/log_name, time-adjacent rows carry no relationship.
        const rows = groupBursts(
            [at(1, '2026-08-19 09:00:10'), at(2, '2026-08-19 09:00:20'), at(3, '2026-08-19 09:00:30')],
            false,
        );

        expect(rows).toHaveLength(3);
        expect(rows.every((r) => r.type === 'single')).toBe(true);
    });
});

/**
 * `SyncPackage::handle()` writes two `updated` rows per package a resync touches: `syncing`
 * at the start, then `synced`/`failed` at the end — both landing in the same minute with the
 * same log_name/event/subject_type/causer. Builds that pair for one subject, one second
 * apart, so several subjects' pairs can share a minute and stay contiguous.
 */
function syncPhasePair(startId: number, second: number, subjectId: string, outcome: 'synced' | 'failed'): ActivityEntry[] {
    const pad = (n: number) => String(n).padStart(2, '0');

    return [
        {
            ...at(startId, `2026-08-19 09:00:${pad(second)}`),
            subject_id: subjectId,
            changes: { attributes: { sync_status: 'syncing' }, old: { sync_status: 'pending' } },
        },
        {
            ...at(startId + 1, `2026-08-19 09:00:${pad(second + 1)}`),
            subject_id: subjectId,
            changes: { attributes: { sync_status: outcome }, old: { sync_status: 'syncing' } },
        },
    ];
}

describe('groupBursts — per-subject collapsing of sync write-phase pairs', () => {
    it('counts distinct subjects, not rows, when every subject wrote a syncing row and a synced row', () => {
        const entries = Array.from({ length: 12 }, (_, i) => syncPhasePair(100 + i * 2, i * 2, `pkg-${i + 1}`, 'synced')).flat();

        const rows = groupBursts(entries, true);

        expect(rows).toHaveLength(1);
        expect(rows[0].type).toBe('burst');
        // The expanded view still shows every row — all 24 of them.
        expect(rows[0].type === 'burst' && rows[0].entries).toHaveLength(24);
        // But the headline count and the outcome describe the 12 packages, not the 24 rows.
        expect(rows[0].type === 'burst' && rows[0].count).toBe(12);
        expect(rows[0].type === 'burst' && rows[0].outcome).toBe('12 erfolgreich');
    });

    it('tallies success/failure per subject rather than per row', () => {
        const entries = [
            ...Array.from({ length: 11 }, (_, i) => syncPhasePair(200 + i * 2, i * 2, `pkg-${i + 1}`, 'synced')).flat(),
            ...syncPhasePair(300, 22, 'pkg-12', 'failed'),
        ];

        const rows = groupBursts(entries, true);

        expect(rows[0].type === 'burst' && rows[0].count).toBe(12);
        expect(rows[0].type === 'burst' && rows[0].outcome).toBe('11 erfolgreich, 1 fehlgeschlagen');
    });

    it('reports no outcome while one subject is still mid-flight, but still counts it as one subject', () => {
        const entries: ActivityEntry[] = [
            ...Array.from({ length: 11 }, (_, i) => syncPhasePair(400 + i * 2, i * 2, `pkg-${i + 1}`, 'synced')).flat(),
            // pkg-12 has only fired its first write phase so far — no terminal row exists yet.
            {
                ...at(500, '2026-08-19 09:00:22'),
                subject_id: 'pkg-12',
                changes: { attributes: { sync_status: 'syncing' }, old: { sync_status: 'pending' } },
            },
        ];

        const rows = groupBursts(entries, true);

        expect(rows[0].type === 'burst' && rows[0].count).toBe(12);
        expect(rows[0].type === 'burst' && rows[0].outcome).toBeNull();
    });
});

describe('burstOutcome', () => {
    it('counts successes and failures when every entry carries a readable sync_status', () => {
        expect(
            burstOutcome([
                withSync(1, '2026-08-19 09:00:10', 'synced'),
                withSync(2, '2026-08-19 09:00:20', 'synced'),
                withSync(3, '2026-08-19 09:00:30', 'failed'),
            ]),
        ).toBe('2 erfolgreich, 1 fehlgeschlagen');
    });

    it('degrades to null — count-only — when an entry carries no derivable outcome', () => {
        expect(
            burstOutcome([
                withSync(1, '2026-08-19 09:00:10', 'synced'),
                withSync(2, '2026-08-19 09:00:20', 'synced'),
                at(3, '2026-08-19 09:00:30'),
            ]),
        ).toBeNull();
    });

    it('degrades to null when the status is present but not success/failure', () => {
        expect(
            burstOutcome([
                withSync(1, '2026-08-19 09:00:10', 'synced'),
                withSync(2, '2026-08-19 09:00:20', 'synced'),
                withSync(3, '2026-08-19 09:00:30', 'syncing'),
            ]),
        ).toBeNull();
    });
});
