import { describe, expect, it } from 'vitest';
import {
    offersRegistryPicker,
    offersSetupBand,
    REGISTRY_PICKER_LABEL,
    selectedRegistry,
    setupBand,
    setupSteps,
    type PortalSetupRegistry,
} from './portalSetupBand';

const INTERN: PortalSetupRegistry = { id: 'reg-1', name: 'Intern', url: 'https://registry.example.test/r/3b/intern' };
const IMAGES: PortalSetupRegistry = { id: 'reg-2', name: 'Images', url: 'https://images.3b.de' };

describe('setupBand', () => {
    it('asks for a token when the organization has no valid one', () => {
        // Asserted WHOLE, the rule this directory's tests already follow: a `toContain('Token')`
        // survives every rewrite that keeps the word, and the word is not the claim.
        //
        // "derzeit kein gültiges", NOT "noch kein": the server sends `none` for an EXISTING
        // token that was revoked or has expired as well (PortalSetupBandTest pins both), so a
        // sentence claiming the organization never had one is false for exactly the customer
        // whose build has just started failing. And "Ihre Organisation", not "Sie": the state
        // is organization-wide while the setup page's token list is filtered by user, so a
        // colleague reading "Sie haben" would be told something the next page contradicts.
        expect(setupBand('none', null)).toEqual({
            collapsed: false,
            title: 'Zugang einrichten',
            lead: 'Einmal pro Rechner. Danach installieren Sie aus dieser Registry wie aus jeder anderen.',
            note: 'Ihre Organisation hat derzeit kein gültiges Zugriffstoken.',
            action: 'Einrichtung öffnen',
        });
    });

    it('keeps the band open for a token that exists but was never used', () => {
        // The middle state, and the one a two-state rule would get wrong: a token in hand is
        // NOT a finished setup — nothing has authenticated with it — so the steps stay. Only
        // the note differs from `none`, and it says which of the three steps is already done.
        expect(setupBand('unused', null)).toEqual({
            collapsed: false,
            title: 'Zugang einrichten',
            lead: 'Einmal pro Rechner. Danach installieren Sie aus dieser Registry wie aus jeder anderen.',
            note: 'Ihre Organisation hat bereits ein Zugriffstoken, es wurde aber noch nicht benutzt.',
            action: 'Einrichtung öffnen',
        });
    });

    it('collapses to one line naming the token and when it was last used', () => {
        // THE STATE THIS BAND EXISTS FOR. A hint that stays after the work is done is
        // wallpaper, and wallpaper is not read when it later says something urgent — so the
        // assertion that matters is `collapsed: true` together with the absence of the lead
        // and the note, not the sentence alone.
        expect(setupBand('used', { name: 'ci-token', used_at: 'vor 2 Stunden' })).toEqual({
            collapsed: true,
            title: 'Zugang eingerichtet · Ihre Organisation hat ci-token zuletzt vor 2 Stunden genutzt',
            lead: null,
            note: null,
            action: 'Einrichtung ansehen',
        });
    });

    it('carries a different token through', () => {
        // The interpolation's absent case: the assertion above is equally satisfied by a
        // constant with `ci-token` typed into it, which would name the wrong credential on
        // every other portal — and the name is the only part of that line a customer can act on.
        expect(setupBand('used', { name: 'laptop', used_at: 'vor 3 Tagen' }).title).toBe(
            'Zugang eingerichtet · Ihre Organisation hat laptop zuletzt vor 3 Tagen genutzt',
        );
    });

    it('does not re-derive the state from the token', () => {
        // The rule this module deliberately does NOT own. `PackageController` decides what a
        // usable token is (not revoked, not expired) and sends the finished state; a band
        // that read `lastUsed !== null` for itself would be a second statement of that rule
        // in the one place that cannot see the data, and the two would disagree the moment
        // the server's definition changes. Here: a used token that the server nonetheless
        // reports as `none` — the shape a revocation produces — must not collapse the band.
        expect(setupBand('none', { name: 'ci-token', used_at: 'vor 2 Stunden' }).collapsed).toBe(false);
    });
});

describe('setupSteps', () => {
    it('names the three steps in order, over the ecosystems the organization may serve', () => {
        // The steps are five German sentences with no other home. Asserted whole and in
        // ORDER: they are a route ("create, configure, install") and a reordering would
        // describe a sequence that does not work.
        expect(setupSteps(['Composer', 'npm', 'Python', 'Docker'])).toEqual([
            { title: 'Token erstellen', detail: 'Ein Lese-Token genügt zum Installieren.' },
            { title: 'Werkzeug konfigurieren', detail: 'Für Composer, npm, Python oder Docker — je nachdem, was diese Registry führt.' },
            { title: 'Paket installieren', detail: 'Der Befehl steht auf jeder Paketseite.' },
        ]);
    });

    it('names only what a narrowed organization may serve', () => {
        // The finding this function exists for: the second step used to name all four
        // ecosystems from plate 1, and its button leads to the Einrichtung tab — which since
        // task 3 shows only the permitted ones. Naming four and then showing two is the same
        // false claim that task removed, one page earlier.
        expect(setupSteps(['Composer', 'Docker'])[1].detail).toBe('Für Composer oder Docker — je nachdem, was diese Registry führt.');
    });

    it('does not offer a choice when only one ecosystem is enabled', () => {
        // "je nachdem" is a false promise with one type: there is nothing to choose between.
        expect(setupSteps(['Docker'])[1].detail).toBe('Für Docker — den einzigen Paket-Typ, den Ihre Organisation nutzen darf.');
    });

    it('says so when the organization may serve nothing at all', () => {
        // `enabled_registry_types = []` is a state the console accepts and stores, and the
        // page this band leads to answers it with `noEcosystemMessage()`. A list joined out
        // of zero names would be "Für  — je nachdem", a sentence with a hole in it.
        expect(setupSteps([])[1].detail).toBe('Für Ihre Organisation ist derzeit kein Paket-Typ freigeschaltet.');
    });

    it('keeps the other two steps free of the ecosystem list', () => {
        // The first and third step are the same sentence whatever is enabled — the boundary
        // that catches a detail builder wired into the wrong index.
        const steps = setupSteps([]);

        expect(steps[0].detail).toBe('Ein Lese-Token genügt zum Installieren.');
        expect(steps[2].detail).toBe('Der Befehl steht auf jeder Paketseite.');
    });
});

describe('REGISTRY_PICKER_LABEL', () => {
    it('captions the registry picker', () => {
        // Copy, and therefore here: the band's one remaining inline string used to sit in the
        // template, where nothing in this project can read it.
        expect(REGISTRY_PICKER_LABEL).toBe('Registry');
    });
});

describe('offersSetupBand', () => {
    it('renders the band for an organization with a registry', () => {
        expect(offersSetupBand([INTERN])).toBe(true);
    });

    it('renders nothing when the portal shows no registry at all', () => {
        // A portal-enabled organization whose registries are all hidden (or which has none)
        // is reachable, and the band's button would build `portal.registries.show` from an
        // id it does not have — a Ziggy exception on the customer's landing page rather
        // than a missing sentence.
        expect(offersSetupBand([])).toBe(false);
    });
});

describe('offersRegistryPicker', () => {
    it('offers a choice between two registries', () => {
        expect(offersRegistryPicker([INTERN, IMAGES])).toBe(true);
    });

    it('does not offer a picker holding the only registry there is', () => {
        // The boundary in both directions: `>= 1` passes the case above and fails here.
        expect(offersRegistryPicker([INTERN])).toBe(false);
    });

    it('does not offer an empty picker', () => {
        expect(offersRegistryPicker([])).toBe(false);
    });
});

describe('selectedRegistry', () => {
    it('returns the picked registry', () => {
        expect(selectedRegistry([INTERN, IMAGES], 'reg-2')).toBe(IMAGES);
    });

    it('falls back to the first registry when the selection matches nothing', () => {
        // The selection is browser state and the list is a prop: a partial reload that drops
        // the picked registry leaves the selection pointing at a row that is gone, and
        // reading `.url` off `undefined` there is a blank band and a broken button.
        expect(selectedRegistry([INTERN, IMAGES], 'reg-gone')).toBe(INTERN);
    });

    it('answers null for an empty list', () => {
        // `offersSetupBand()` is what keeps this out of the template, but the function must
        // still be total: `registries[0]` on an empty array is `undefined`, which typing
        // alone does not catch under a non-exact index signature.
        expect(selectedRegistry([], 'reg-1')).toBeNull();
    });
});
