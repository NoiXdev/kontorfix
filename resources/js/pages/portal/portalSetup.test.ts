import { describe, expect, it } from 'vitest';
import { orgTokenScopeWarning, orgTokensEmptyMessage, orgTokensHeading, revokeConfirmation, setupIntro } from './portalSetup';

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

describe('orgTokensHeading', () => {
    it('names the list as organization-wide, distinct from a per-registry Zugriffstokens tab', () => {
        expect(orgTokensHeading()).toBe('Ihre organisationsweiten Tokens');
    });
});

describe('orgTokensEmptyMessage', () => {
    it('states the empty case for the revoke table', () => {
        expect(orgTokensEmptyMessage()).toBe('Noch keine organisationsweiten Tokens erstellt.');
    });
});

describe('revokeConfirmation', () => {
    it('asks the same question the per-registry tab asks before revoking', () => {
        expect(revokeConfirmation()).toBe('Token wirklich widerrufen?');
    });
});
