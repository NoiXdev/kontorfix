import { describe, expect, it } from 'vitest';
import { GRACE_HELD_CAPTION, SWEEP_CONFIRMATION, SWEEPER_EXPLANATION } from './sweeper';

describe('sweeper copy', () => {
    it('names all four jobs and disclaims rule knowledge', () => {
        expect(SWEEPER_EXPLANATION).toContain('Manifeste');
        expect(SWEEPER_EXPLANATION).toContain('Blobs');
        expect(SWEEPER_EXPLANATION).toContain('Upload-Sessions');
        expect(SWEEPER_EXPLANATION).toContain('beim Push angelegte Repositories');
        // The separation the whole design rests on: the sweeper knows no rules.
        expect(SWEEPER_EXPLANATION).toContain('Regeln kennt sie nicht');
    });

    it('explains a non-zero grace count as the mechanism working, not a fault', () => {
        // The figure exists to be seen (spec: shown, not hidden) — and without this
        // sentence, a growing number during a push reads as a problem to report.
        expect(GRACE_HELD_CAPTION).toContain('kein Fehler');
        expect(GRACE_HELD_CAPTION).toContain('laufende Pushes');
    });

    it('promises the dialog only what the sweeper guarantees', () => {
        expect(SWEEP_CONFIRMATION).toContain('unerreichbar');
        expect(SWEEP_CONFIRMATION).toContain('Schonfrist');
        expect(SWEEP_CONFIRMATION).toContain('nie betroffen');
    });

    it('keeps the formal register', () => {
        for (const copy of [SWEEPER_EXPLANATION, GRACE_HELD_CAPTION, SWEEP_CONFIRMATION]) {
            expect(copy).not.toMatch(/\bdu\b|\bdein/i);
        }
    });
});
