import { describe, expect, it } from 'vitest';
import { offersMinting } from './registrySetup';

describe('offersMinting', () => {
    it('offers minting when the caller does not ask about it', () => {
        // THE CONSOLE'S CASE, and the one where a mistake does the most damage: the admin
        // pages render RegistrySetup without the prop, so a `?? true` written as `=== true`
        // — or any default of false — would hide the token form from every admin on every
        // registry page, not merely from an operator inside a customer portal.
        expect(offersMinting(undefined)).toBe(true);
    });

    it('offers minting when the caller says yes', () => {
        expect(offersMinting(true)).toBe(true);
    });

    it('withholds minting only on an explicit no', () => {
        // What the portal passes for an account that may not mint in the addressed
        // organization. The three cases together pin `!== false` rather than a truthiness
        // test, which would collapse undefined and false into the same answer.
        expect(offersMinting(false)).toBe(false);
    });
});
