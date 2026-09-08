import { describe, expect, it } from 'vitest';
import {
    PORTAL_NO_REMOVALS,
    PORTAL_NO_RETENTION,
    PORTAL_RETENTION_ADVICE,
    PORTAL_RETENTION_EXPLANATION,
    PORTAL_UPCOMING_REMOVALS,
} from './portalRetention';

describe('portal retention copy', () => {
    it('names the operator as the rule-maker', () => {
        // The customer cannot change the rules; copy that leaves the author open invites a
        // support ticket asking where the setting is.
        expect(PORTAL_RETENTION_EXPLANATION).toContain('Betreiber');
    });

    it('states the empty case as a fact, not an absence', () => {
        expect(PORTAL_NO_RETENTION).toContain('nichts automatisch entfernt');
    });

    it('tells the customer what to do about a tag they still need', () => {
        // The sentence that makes the preview useful rather than merely honest.
        expect(PORTAL_RETENTION_ADVICE).toContain('ziehen Sie');
        expect(PORTAL_RETENTION_ADVICE).toContain('neuen Tag');
    });

    it('hedges the forecast — the run re-evaluates', () => {
        expect(PORTAL_UPCOMING_REMOVALS).toContain('voraussichtlich');
        expect(PORTAL_NO_REMOVALS).toContain('nach aktuellem Stand');
    });

    it('keeps the formal register', () => {
        for (const copy of [
            PORTAL_RETENTION_EXPLANATION,
            PORTAL_NO_RETENTION,
            PORTAL_RETENTION_ADVICE,
            PORTAL_UPCOMING_REMOVALS,
            PORTAL_NO_REMOVALS,
        ]) {
            expect(copy).not.toMatch(/\bdu\b|\bdein/i);
        }
    });
});
