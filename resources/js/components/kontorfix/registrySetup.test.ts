import { describe, expect, it } from 'vitest';
import { noEcosystemMessage, offersMinting, offersPublishing, stepsForEcosystems } from './registrySetup';

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

describe('offersPublishing', () => {
    it('offers the publish ability when the caller does not ask about it', () => {
        // THE CONSOLE'S CASE. Every caller there is an admin or maintainer of the
        // organization, which is exactly whom RegistryTokenPolicy::create() allows Publish,
        // so a default of false would take the ability away from every admin on every
        // registry page — the mirror image of the portal defect this pairs with.
        expect(offersPublishing(undefined)).toBe(true);
    });

    it('offers the publish ability when the caller says yes', () => {
        expect(offersPublishing(true)).toBe(true);
    });

    it('withholds the publish ability only on an explicit no', () => {
        // What the portal passes for an account that may not publish in the addressed
        // organization. The three cases together pin `!== false` rather than a truthiness
        // test, which would collapse undefined and false into the same answer.
        expect(offersPublishing(false)).toBe(false);
    });
});

describe('stepsForEcosystems', () => {
    // The same shape RegistrySetup.vue's own step table has, small enough to read here.
    const steps = [
        { key: 'composer', eco: 'composer' },
        { key: 'auth', eco: 'composer' },
        { key: 'npm', eco: 'npm' },
        { key: 'pip', eco: 'python' },
        { key: 'twine', eco: 'python' },
        { key: 'docker', eco: 'docker' },
    ] as const;

    it('shows nothing at all for an organization that may serve nothing', () => {
        // THE CASE THIS FUNCTION EXISTS FOR, and the one the component got wrong: it read
        // `types.length ? types : ['composer', 'npm', 'python']`, so an organization with
        // `enabled_registry_types = []` — a value the console accepts and stores, pinned
        // server-side in RegistrySetupTest — was shown Composer, auth.json, npm, pip and
        // twine instructions for a registry whose every endpoint answers 404. Restoring
        // that fallback inside stepsForEcosystems() turns this red: five steps come back
        // where none may.
        expect(stepsForEcosystems(steps, [])).toEqual([]);
    });

    it('shows every step of every permitted ecosystem, two of them per ecosystem', () => {
        expect(stepsForEcosystems(steps, ['composer', 'python']).map((s) => s.key)).toEqual(['composer', 'auth', 'pip', 'twine']);
    });

    it('shows only docker for a registry permitted images alone', () => {
        // The reversal of the empty case: a fallback keyed on "is the list short?" rather
        // than on the list itself would substitute the three non-Docker ecosystems here
        // too, which is the wrong answer for a registry that serves images only.
        expect(stepsForEcosystems(steps, ['docker']).map((s) => s.key)).toEqual(['docker']);
    });

    it('ignores a type it has no step for rather than showing everything', () => {
        // effectiveFor() is driven by PackageType, so a new ecosystem can reach this prop
        // before the step table knows it. Skipping it is the only safe answer; the
        // alternative a fallback invites is "unrecognised list → show the default set".
        expect(stepsForEcosystems(steps, ['golang'])).toEqual([]);
    });
});

describe('noEcosystemMessage', () => {
    it('states the empty case instead of instructions that would not work', () => {
        expect(noEcosystemMessage()).toBe(
            'Für diese Registry ist derzeit kein Paket-Typ freigeschaltet. Einrichtungs-Schritte finden Sie hier, ' +
                'sobald mindestens ein Typ freigegeben ist — bis dahin beantwortet die Registry jede Client-Anfrage mit 404.',
        );
    });

    it('names no page, so one string serves both audiences', () => {
        // Unlike dockerDomainNote(), which splits precisely because its operator half names
        // a console page a portal account gets 403 from. Pinned as an absence so a later
        // "helpful" pointer cannot be added here without splitting the function first.
        expect(noEcosystemMessage()).not.toContain('Registry →');
        expect(noEcosystemMessage()).not.toContain('Organisation →');
        expect(noEcosystemMessage()).not.toContain('/admin');
    });
});
