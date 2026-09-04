import { describe, expect, it } from 'vitest';
import { offersSwitcher, operatorBannerNote, portalAreaLinks, switcherOptions } from './portalHeader';

const HOME = { name: 'Home', slug: 'home' };
const OTHER = { name: 'Other', slug: 'other' };
const CUSTOMER = { name: 'Acme GmbH', slug: 'acme' };
const AREAS = { packages: '/c/acme', registries: '/c/acme/registries' };

describe('operatorBannerNote', () => {
    it('names the customer and says which affordance the operator does not have', () => {
        // Asserted WHOLE, and for the reason lapsedNote's own test spells out: a
        // `toContain('Betreiber')` survives every rewrite that keeps the word, and
        // `not.toContain` is case-sensitive on top of that.
        //
        // The second sentence is a claim about CONTENT plus the one missing action. An
        // earlier draft promised the view matched the customer's, which the hidden token
        // form made false — and a banner promising an identical view above a form the
        // customer has and the operator does not makes the hiding read as a bug.
        expect(operatorBannerNote('Acme GmbH')).toBe(
            'Sie sehen das Portal von Acme GmbH als Betreiber. Diese Ansicht zeigt die Inhalte des Kunden; Tokens können Sie hier nicht erstellen.',
        );
    });

    it('carries a different name through', () => {
        // The ABSENT case for the interpolation: the assertion above is equally satisfied by
        // a constant sentence with "Acme GmbH" typed into it, which would name the wrong
        // customer on every other portal.
        expect(operatorBannerNote('Beispiel AG')).toBe(
            'Sie sehen das Portal von Beispiel AG als Betreiber. Diese Ansicht zeigt die Inhalte des Kunden; Tokens können Sie hier nicht erstellen.',
        );
    });
});

describe('switcherOptions', () => {
    it('labels each organization by name and carries its slug as the value', () => {
        // toEqual on the whole list: the slug is what the switcher navigates to, so a
        // name/slug swap sends the viewer to a portal named after the wrong customer — and
        // a per-key assertion would not see a swap of two rows either.
        expect(switcherOptions([OTHER, HOME], HOME)).toEqual([
            { value: 'other', label: 'Other' },
            { value: 'home', label: 'Home' },
        ]);
    });

    it('adds the addressed organization as a disabled row when the viewer is not a member', () => {
        // The operator case. Without the row the control's selected value matched nothing
        // and it rendered its "Bitte wählen" placeholder, as if no portal were open.
        expect(switcherOptions([HOME], CUSTOMER)).toEqual([
            { value: 'acme', label: 'Acme GmbH', disabled: true },
            { value: 'home', label: 'Home' },
        ]);
    });

    it('does not add a second row for an organization already in the list', () => {
        // The ABSENT case for that prepend: prepending unconditionally would show a member
        // their own organization twice, once unselectable.
        expect(switcherOptions([HOME], HOME)).toEqual([{ value: 'home', label: 'Home' }]);
    });
});

describe('offersSwitcher', () => {
    it('offers a choice between two organizations', () => {
        expect(offersSwitcher(switcherOptions([HOME, OTHER], HOME))).toBe(true);
    });

    it('offers an operator the way back to their own portal', () => {
        // One own membership plus the disabled current row is still a real choice, so this
        // has to be asked of the OPTIONS: `switchable.length > 1` is false here and would
        // strand the operator inside the customer's portal.
        expect(offersSwitcher(switcherOptions([HOME], CUSTOMER))).toBe(true);
    });

    it('does not offer a picker holding only the organization already on screen', () => {
        // The boundary, in both directions: `>= 1` passes the first case and fails here,
        // `> 2` passes here and fails there.
        expect(offersSwitcher(switcherOptions([HOME], HOME))).toBe(false);
    });

    it('does not offer an empty picker', () => {
        expect(offersSwitcher([])).toBe(false);
    });
});

describe('portalAreaLinks', () => {
    it('names both areas, in order, and marks the package list on the landing page', () => {
        // toEqual on the WHOLE list. The registries area spent this branch with no link into
        // it anywhere in resources/js — the setup snippets and the token form were unreachable
        // for a customer whose package list is empty — so the assertion that matters is that
        // the second entry is there at all, with the right address. A per-key check on the
        // first entry would have passed throughout the regression.
        expect(portalAreaLinks(AREAS, '/c/acme')).toEqual([
            { label: 'Pakete', href: '/c/acme', current: true },
            { label: 'Registries', href: '/c/acme/registries', current: false },
        ]);
    });

    it('marks the registries area on the registries list', () => {
        // The other side of `current`. Without it the flag is indistinguishable from the
        // constant `true` on the first row and `false` on the second, which is what a landing
        // page assertion alone leaves it as.
        expect(portalAreaLinks(AREAS, '/c/acme/registries')).toEqual([
            { label: 'Pakete', href: '/c/acme', current: false },
            { label: 'Registries', href: '/c/acme/registries', current: true },
        ]);
    });

    it('keeps a registry detail page inside the registries area', () => {
        // A prefix, not equality: the registry detail and the package detail below it are both
        // addressed under /registries/…, and equality would drop the marking the moment the
        // customer clicked a card — leaving the navigation pointing at Pakete on a page that is
        // not the package list.
        expect(portalAreaLinks(AREAS, '/c/acme/registries/019-abc/packages/019-def').map((a) => a.current)).toEqual([false, true]);
    });

    it('ignores the package list search and filter keys', () => {
        // useTableState writes `pkg_search` and `pkg_type` into the URL, so the landing page's
        // own address carries a query string as soon as the customer types. A navigation that
        // stopped marking itself there would be worse than one that never marked itself.
        expect(portalAreaLinks(AREAS, '/c/acme?pkg_search=tools&pkg_type=composer').map((a) => a.current)).toEqual([true, false]);
    });

    it('carries a different organization through', () => {
        // The interpolation's absent case: the assertions above are equally satisfied by two
        // literals with `acme` typed into them, which would send every other customer into a
        // stranger's portal.
        const other = { packages: '/c/beispiel-ag', registries: '/c/beispiel-ag/registries' };

        expect(portalAreaLinks(other, '/c/beispiel-ag')).toEqual([
            { label: 'Pakete', href: '/c/beispiel-ag', current: true },
            { label: 'Registries', href: '/c/beispiel-ag/registries', current: false },
        ]);
    });
});
