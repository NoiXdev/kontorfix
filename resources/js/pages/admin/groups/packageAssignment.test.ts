import { describe, expect, it } from 'vitest';
import {
    availabilityLabel,
    availabilityNote,
    availabilityOf,
    endsImmediately,
    EXPIRY_CONSEQUENCE,
    formatDay,
    IMMEDIATE_WITHDRAWAL_NOTE,
} from './packageAssignment';

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
        // …that the request does not reach the upstream rather than falling through to it…
        expect(note).toContain('nicht an den Upstream');
        // …and the act that releases the name.
        expect(note).toContain('entfernen');
    });

    it('warns before the date what the date will do', () => {
        const note = availabilityNote({ state: 'limited', day: '31.12.2026' });

        expect(note).toContain('31.12.2026');
        expect(note).toContain('404');
        expect(note).toContain('nicht an den Upstream');
        expect(note).toContain('entfernt');
    });

    it('never calls the upstream by a single vendor name', () => {
        // The registry's upstream URL is configurable and has its own tab on this page, so
        // naming Packagist as the destination would be wrong as often as it was right.
        // EXPIRY_CONSEQUENCE names it once, explicitly as an example.
        for (const state of ['limited', 'lapsed'] as const) {
            expect(availabilityNote({ state, day: '31.12.2026' })).not.toContain('Packagist');
        }
    });
});

describe('endsImmediately', () => {
    it('is true only for a day that is already over', () => {
        // The direction, stated over one fixed "today": yesterday takes effect at once,
        // today does not (the stored value is the END of today) and neither does tomorrow.
        expect(endsImmediately('2026-06-30', '2026-07-01')).toBe(true);
        expect(endsImmediately('2026-07-01', '2026-07-01')).toBe(false);
        expect(endsImmediately('2026-07-02', '2026-07-01')).toBe(false);
    });

    it('is false for an empty field, which means no date at all', () => {
        expect(endsImmediately('', '2026-07-01')).toBe(false);
    });

    it('compares whole days, not months or years, across a boundary', () => {
        expect(endsImmediately('2025-12-31', '2026-01-01')).toBe(true);
        expect(endsImmediately('2026-01-02', '2025-12-31')).toBe(false);
    });
});

describe('IMMEDIATE_WITHDRAWAL_NOTE', () => {
    it('says delivery stops at once and the name still does not reach the upstream', () => {
        expect(IMMEDIATE_WITHDRAWAL_NOTE).toContain('sofort');
        expect(IMMEDIATE_WITHDRAWAL_NOTE).toContain('nicht an den Upstream');
    });

    it('distinguishes withdrawing from detaching', () => {
        // The two ways to stop delivering. Only one of them keeps the name suppressed, and
        // an operator who confuses them opens the very fallthrough spec §4 closes.
        expect(IMMEDIATE_WITHDRAWAL_NOTE).toContain('Entfernen der Zuweisung');
        expect(IMMEDIATE_WITHDRAWAL_NOTE).toContain('frei');
    });
});

describe('EXPIRY_CONSEQUENCE', () => {
    it('states that the upstream is not reopened by the date passing', () => {
        expect(EXPIRY_CONSEQUENCE).toContain('404');
        expect(EXPIRY_CONSEQUENCE).toContain('nicht an den Upstream');
        expect(EXPIRY_CONSEQUENCE).toContain('bis die Zuweisung entfernt wird');
    });

    it('says what an empty date field means', () => {
        expect(EXPIRY_CONSEQUENCE).toContain('unbefristet');
    });
});
