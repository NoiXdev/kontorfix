import { describe, expect, it } from 'vitest';
import { OCI_AUTO_CREATE_COST, OCI_AUTO_CREATE_LABEL } from './ociAutoCreate';

describe('oci auto-create copy', () => {
    it('spells the label exactly as the server quotes it back', () => {
        // Asserted WHOLE and as a literal, deliberately: this string is duplicated in PHP, in
        // `OciException::nameUnknownAutoCreateDisabled()`, which tells a refused pusher which
        // switch to look for. A `toContain('Push')` would survive every rewrite that keeps the
        // word, and the word is not the claim — the caption matching what the operator reads
        // in the error message is.
        expect(OCI_AUTO_CREATE_LABEL).toBe('Repositories beim Push anlegen');
    });

    it('names what an abandoned push leaves behind, not merely that something is left behind', () => {
        // The three surfaces the leftover rows show up on are the reason the sentence exists —
        // an operator who reads only "kann Reste hinterlassen" has been told nothing they can
        // act on. Formal "Sie"-register throughout, like the rest of the console.
        expect(OCI_AUTO_CREATE_COST).toContain('ersten Upload-Request');
        expect(OCI_AUTO_CREATE_COST).toContain('Abgebrochene Pushes');
        expect(OCI_AUTO_CREATE_COST).toContain('Paketlisten');
        expect(OCI_AUTO_CREATE_COST).toContain('Kundenportal');
        expect(OCI_AUTO_CREATE_COST).toContain('von Hand');
        expect(OCI_AUTO_CREATE_COST).not.toMatch(/\bdu\b|\bdein/i);
    });
});
