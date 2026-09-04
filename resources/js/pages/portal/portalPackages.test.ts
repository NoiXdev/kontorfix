import { describe, expect, it } from 'vitest';
import { badgesFor, lapsedNote, registryMarker } from './portalPackages';

describe('badgesFor', () => {
    it('marks a shared package', () => {
        expect(badgesFor({ shared: true, in_force: true })).toContain('geteilt');
    });

    it('marks a lapsed assignment', () => {
        expect(badgesFor({ shared: true, in_force: false })).toContain('abgelaufen');
    });

    it('gives an own, in-force package no badge', () => {
        expect(badgesFor({ shared: false, in_force: true })).toEqual([]);
    });
});

describe('lapsedNote', () => {
    it('says what the customer sees in their build', () => {
        expect(lapsedNote()).toContain('404');
    });

    it('does not tell the customer to remove anything themselves', () => {
        // Only the operator can restore the assignment; an instruction the reader cannot
        // follow is worse than none.
        expect(lapsedNote()).not.toContain('Entfernen Sie');
    });
});

describe('registryMarker', () => {
    // The row badge and this marker answer different questions, and a package live in one
    // registry and lapsed in another is where they disagree: the row says the package is
    // usable, so it carries no lapsed badge, and only this marker tells the customer which
    // of their registries stopped serving it. Rendering the row state alone would report
    // "all fine" over a link that 404s.
    it('leaves a registry that still serves the package unmarked', () => {
        expect(registryMarker({ in_force: true, available_until: '2026-12-31' })).toBeNull();
    });

    it('names the day the registry stopped serving it', () => {
        expect(registryMarker({ in_force: false, available_until: '2026-08-31' })).toBe('abgelaufen am 31.08.2026');
    });

    it('marks a registry that no longer serves the package even with no end date', () => {
        // `in_force` is false without a date whenever the assignment lapsed for a reason
        // other than expiry — a shared package whose sharing was withdrawn, say. The
        // registry answers 404 all the same, so the link is marked all the same.
        expect(registryMarker({ in_force: false, available_until: null })).toBe('abgelaufen');
    });
});
