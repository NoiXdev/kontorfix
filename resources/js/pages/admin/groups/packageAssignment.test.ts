import { describe, expect, it } from 'vitest';
import { availabilityLabel, availabilityNote, availabilityOf, EXPIRY_CONSEQUENCE, formatDay } from './packageAssignment';

/*
 * The cases are read off the two rules this module describes, not off its branches:
 *
 *  1. An assignment with a date is in force or lapsed, and the SERVER decides which. The
 *     same date therefore has to produce opposite answers depending on `in_force` — which
 *     is why every case below fixes the date and varies only that flag. A classifier that
 *     re-derived the state from the date would pass a suite that only ever paired a past
 *     date with `in_force: false`, and would then disagree with the registry at the
 *     boundary — the exact defect this column was surfaced to fix.
 *  2. An expiry does not release the name. Both texts an operator reads before and after a
 *     date passes must say so; a text that says only "afterwards it is no longer available"
 *     is the plausible, shorter, WRONG wording, so it is asserted against directly.
 */

describe('availabilityOf', () => {
    it('calls an assignment without a date open-ended', () => {
        expect(availabilityOf({ available_until: null, in_force: true })).toEqual({ state: 'permanent', day: null });
    });

    it('takes the in-force answer from the server, not from the date', () => {
        // One date, both answers. Nothing about '2026-12-31' decides this.
        expect(availabilityOf({ available_until: '2026-12-31', in_force: true }).state).toBe('limited');
        expect(availabilityOf({ available_until: '2026-12-31', in_force: false }).state).toBe('lapsed');
    });

    it('keeps the day in both states', () => {
        expect(availabilityOf({ available_until: '2026-12-31', in_force: true }).day).toBe('31.12.2026');
        expect(availabilityOf({ available_until: '2026-12-31', in_force: false }).day).toBe('31.12.2026');
    });
});

describe('formatDay', () => {
    it('renders the German day order', () => {
        expect(formatDay('2026-12-31')).toBe('31.12.2026');
    });

    it('does not shift the day across a timezone', () => {
        // Parsed as UTC midnight and formatted locally, this date is the 31st in Berlin and
        // the 30th west of Greenwich. Pure string surgery has no such failure mode, and this
        // pins it: a `new Date(day)` rewrite would go red on any machine behind UTC.
        expect(formatDay('2026-01-01')).toBe('01.01.2026');
        expect(formatDay('2026-12-31T23:59:59+00:00')).toBe('31.12.2026');
    });
});

describe('availabilityLabel', () => {
    it('names the day for a limited assignment', () => {
        expect(availabilityLabel({ state: 'limited', day: '31.12.2026' })).toBe('bis 31.12.2026');
    });

    it('says a lapsed assignment has lapsed, and when', () => {
        // Not merely "abgelaufen": the date is what turns "why is this failing?" into an
        // answer, and it is the only place the operator sees it once the row is out of force.
        expect(availabilityLabel({ state: 'lapsed', day: '31.12.2026' })).toBe('abgelaufen am 31.12.2026');
    });

    it('says an open-ended assignment is open-ended', () => {
        expect(availabilityLabel({ state: 'permanent', day: null })).toBe('unbefristet');
    });
});

describe('availabilityNote', () => {
    it('has nothing to add for an open-ended assignment', () => {
        expect(availabilityNote({ state: 'permanent', day: null })).toBeNull();
    });

    it('warns that a lapsed assignment blocks the name rather than releasing it', () => {
        const note = availabilityNote({ state: 'lapsed', day: '31.12.2026' });

        expect(note).toContain('31.12.2026');
        // What the customer is seeing right now…
        expect(note).toContain('404');
        // …that the name stays blocked rather than falling back…
        expect(note).toContain('gesperrt');
        // …and the one act that ends it.
        expect(note).toContain('entfernen');
    });

    it('warns before the date what the date will do', () => {
        const note = availabilityNote({ state: 'limited', day: '31.12.2026' });

        expect(note).toContain('31.12.2026');
        expect(note).toContain('404');
        expect(note).toContain('entfernt');
    });
});

describe('EXPIRY_CONSEQUENCE', () => {
    it('states that the public index is not reopened by the date passing', () => {
        expect(EXPIRY_CONSEQUENCE).toContain('404');
        expect(EXPIRY_CONSEQUENCE).toContain('nicht auf den öffentlichen Index');
        expect(EXPIRY_CONSEQUENCE).toContain('bis die Zuweisung entfernt wird');
    });

    it('says what an empty date field means', () => {
        expect(EXPIRY_CONSEQUENCE).toContain('unbefristet');
    });
});
