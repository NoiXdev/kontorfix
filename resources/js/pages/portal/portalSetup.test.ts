import { describe, expect, it } from 'vitest';
import { orgTokenScopeWarning, setupIntro } from './portalSetup';

describe('setupIntro', () => {
    it('states the one address and read-only scope, and that publishing stays per registry', () => {
        expect(setupIntro()).toBe('Eine Quelle für alle Registries der Organisation — nur Lesen; veröffentlicht wird je Registry.');
    });
});

describe('orgTokenScopeWarning', () => {
    it('warns that the scope includes registries the portal does not show', () => {
        expect(orgTokenScopeWarning()).toBe('Gilt für alle Registries der Organisation, auch nicht im Portal sichtbare.');
    });
});
