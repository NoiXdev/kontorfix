import { describe, expect, it } from 'vitest';
import { offersSwitcher, operatorBannerNote, switcherOptions } from './portalHeader';

describe('operatorBannerNote', () => {
    it('names the customer whose portal is on screen and says the view is the customer view', () => {
        // Asserted WHOLE, and for the reason lapsedNote's own test spells out: a
        // `toContain('Betreiber')` survives every rewrite that keeps the word, and
        // `not.toContain` is case-sensitive on top of that. The whole string is the claim —
        // that this is somebody else's portal AND that nothing here is a privileged view of
        // it — and half a claim is the wrong half.
        expect(operatorBannerNote('Acme GmbH')).toBe(
            'Sie sehen das Portal von Acme GmbH als Betreiber. Diese Ansicht entspricht dem, was der Kunde sieht.',
        );
    });

    it('carries a different name through', () => {
        // The ABSENT case for the interpolation: the assertion above is equally satisfied by
        // a constant sentence with "Acme GmbH" typed into it, which would name the wrong
        // customer on every other portal.
        expect(operatorBannerNote('Beispiel AG')).toBe(
            'Sie sehen das Portal von Beispiel AG als Betreiber. Diese Ansicht entspricht dem, was der Kunde sieht.',
        );
    });
});

describe('offersSwitcher', () => {
    it('offers a choice between two organizations', () => {
        expect(offersSwitcher([{ name: 'A', slug: 'a' }, { name: 'B', slug: 'b' }])).toBe(true);
    });

    it('does not offer a picker holding only the organization already on screen', () => {
        // The boundary, in both directions: `>= 1` passes the case above and fails here,
        // `> 2` passes here and fails there.
        expect(offersSwitcher([{ name: 'A', slug: 'a' }])).toBe(false);
    });

    it('does not offer an empty picker', () => {
        expect(offersSwitcher([])).toBe(false);
    });
});

describe('switcherOptions', () => {
    it('labels each organization by name and carries its slug as the value', () => {
        // toEqual on the whole list: the slug is what the switcher navigates to, so a
        // name/slug swap sends the viewer to a portal named after the wrong customer — and
        // a per-key assertion would not see a swap of two rows either.
        expect(
            switcherOptions([
                { name: 'Zeta', slug: 'zeta' },
                { name: 'Alpha', slug: 'alpha' },
            ]),
        ).toEqual([
            { value: 'zeta', label: 'Zeta' },
            { value: 'alpha', label: 'Alpha' },
        ]);
    });
});
