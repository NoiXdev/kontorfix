import { describe, expect, it } from 'vitest';
import { groupByDay, timeOfDay, type ActivityEntry } from './activityGroups';

const at = (id: number, exact: string): ActivityEntry => ({
    id,
    log_name: 'package',
    event: 'updated',
    description: 'updated',
    subject_type: 'Package',
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
