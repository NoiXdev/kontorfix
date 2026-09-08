import { describe, expect, it } from 'vitest';
import { DRY_RUN_EXCLUDES_INLINE_NOTE, DRY_RUN_EXPLANATION, KEEP_RULES_OR, NO_SPACE_FREED_YET, PATTERN_HELP, SHIELD_EXPLANATION } from './policies';

describe('retention policy copy', () => {
    it('states the OR combination and its consequence, whole', () => {
        // toBe, not toContain: the sentence carries a safety claim the evaluator enforces
        // mechanically, and a rewrite that keeps the word "ODER" but flips the consequence
        // ("entfernt mehr") must fail here.
        expect(KEEP_RULES_OR).toBe(
            'Diese Regeln sind mit ODER verknüpft: Ein Tag bleibt erhalten, wenn mindestens eine Regel ihn behält. ' +
                'Eine zusätzliche Regel entfernt daher nie mehr, sondern höchstens weniger.',
        );
    });

    it('describes the shield as a veto with both pinned properties', () => {
        // The two properties the evaluator's mutation tests pin: shield-only is inert, and
        // a shield does not consume a keep_last slot. The copy must state both, or the
        // operator learns a model the code refuses.
        expect(SHIELD_EXPLANATION).toContain('Veto');
        expect(SHIELD_EXPLANATION).toContain('zählen auch nicht gegen „Letzte N behalten“');
        expect(SHIELD_EXPLANATION).toContain('entfernt diese Richtlinie nichts');
    });

    it('promises only the * wildcard', () => {
        expect(PATTERN_HELP).toContain('*');
        // No regex vocabulary: the server matches with Str::is(), and a help text that
        // hints at more than it delivers is a pattern language nobody can predict.
        expect(PATTERN_HELP).not.toMatch(/regex|regul|\.\*/i);
    });

    it('says that applying frees no space until the sweeper runs', () => {
        expect(NO_SPACE_FREED_YET).toContain('entfernt Tags');
        expect(NO_SPACE_FREED_YET).toContain('Speicherbereinigung');
        expect(NO_SPACE_FREED_YET).toContain('Schonfrist');
    });

    it('explains the reason column as the dry run’s point', () => {
        expect(DRY_RUN_EXPLANATION).toContain('entfernt nichts');
        expect(DRY_RUN_EXPLANATION).toContain('welche Regel ihn behält');
    });

    it('states that a package with inline rules falls outside the governed set', () => {
        // Mirrors RetentionRunner::packagesFor()'s `whereNull('retention_rules')` — without
        // this sentence the dry run's package count reads as complete when it silently
        // excludes those repositories.
        expect(DRY_RUN_EXCLUDES_INLINE_NOTE).toContain('eigenen Regeln');
        expect(DRY_RUN_EXCLUDES_INLINE_NOTE).toContain('gehen jeder Richtlinie vor');
    });

    it('keeps the formal register', () => {
        for (const copy of [KEEP_RULES_OR, SHIELD_EXPLANATION, PATTERN_HELP, NO_SPACE_FREED_YET, DRY_RUN_EXPLANATION, DRY_RUN_EXCLUDES_INLINE_NOTE]) {
            expect(copy).not.toMatch(/\bdu\b|\bdein/i);
        }
    });
});
