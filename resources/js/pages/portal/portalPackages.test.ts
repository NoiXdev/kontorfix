import { describe, expect, it } from 'vitest';
import { badgesFor, lapsedNote, noteFor, partlyLapsedNote, registryMarker, SHARED_BADGE_TITLE } from './portalPackages';

describe('badgesFor', () => {
    it('marks a shared package', () => {
        expect(badgesFor({ shared: true, in_force: true })).toEqual(['geteilt']);
    });

    it('marks a lapsed assignment', () => {
        // toEqual, not toContain: it pins the order the page renders in — what the package IS
        // before what is wrong with it — and refuses a stray third badge.
        expect(badgesFor({ shared: true, in_force: false })).toEqual(['geteilt', 'abgelaufen']);
    });

    it('gives an own, in-force package no badge', () => {
        expect(badgesFor({ shared: false, in_force: true })).toEqual([]);
    });
});

describe('lapsedNote', () => {
    it('says what the customer sees in their build', () => {
        expect(lapsedNote()).toContain('404');
    });

    it('points at the markers without promising a date beside each of them', () => {
        // Asserted WHOLE. `not.toContain('Ablaufdatum')` alone let two mutations through the
        // suite: deleting the pointer sentence outright, and rewriting it as "mit dem Datum
        // markiert", which restores the same false promise in other words. `registryMarker`
        // renders a bare `abgelaufen` wherever `available_until` is null, so any promise of a
        // day is false for at least one marker on a mixed row.
        expect(lapsedNote()).toBe(
            'Dieses Paket wird von keiner Ihrer Registries mehr ausgeliefert: Builds erhalten dafür einen 404. ' +
                'Die betroffenen Registries sind oben markiert. Wenden Sie sich an den Betreiber, wenn Sie das ' +
                'Paket weiter benötigen.',
        );
    });

    it('does not tell the customer to remove anything themselves', () => {
        // Only the operator can restore the assignment; an instruction the reader cannot
        // follow is worse than none.
        expect(lapsedNote()).not.toContain('Entfernen Sie');
    });
});

describe('registryMarker', () => {
    // The row badge and this marker answer different questions, and a package live in one
    // registry and lapsed in another is where they disagree: the row says the package is
    // usable, so it carries no lapsed badge, and only this marker tells the customer which
    // of their registries stopped serving it. Rendering the row state alone would report
    // "all fine" over a link that 404s.
    it('leaves a registry that still serves the package unmarked', () => {
        expect(registryMarker({ in_force: true, available_until: '2026-12-31' })).toBeNull();
    });

    it('names the day the registry stopped serving it', () => {
        expect(registryMarker({ in_force: false, available_until: '2026-08-31' })).toBe('abgelaufen am 31.08.2026');
    });

    it('marks a registry that no longer serves the package even with no end date', () => {
        // `in_force` is false without a date whenever the assignment lapsed for a reason
        // other than expiry — a shared package whose sharing was withdrawn, say. The
        // registry answers 404 all the same, so the link is marked all the same.
        expect(registryMarker({ in_force: false, available_until: null })).toBe('abgelaufen');
    });
});

describe('partlyLapsedNote', () => {
    // The row this task exists for. It is in force, so it carries no badge and no lapsedNote(),
    // and without this sentence the customer whose build resolves against the lapsed registry —
    // the only person for whom anything is broken — is told nothing but a date in brackets.
    const live = { name: 'ci', in_force: true };
    const lapsed = { name: 'legacy', in_force: false };

    it('names the registry that stopped serving it and not the one that still does', () => {
        const note = partlyLapsedNote({ shared: false, in_force: true, registries: [live, lapsed] });

        expect(note).toContain('legacy');
        expect(note).not.toContain('ci');
    });

    it('speaks of one registry in the singular', () => {
        // The common case, and the one an earlier wording got wrong in the other direction:
        // "Builds gegen diese Registries" and "die übrigen Registries" about a single lapsed
        // one. Asserted whole, because a plural anywhere in the sentence is the defect.
        expect(partlyLapsedNote({ shared: false, in_force: true, registries: [live, lapsed] })).toBe(
            'In der Registry legacy wird dieses Paket nicht mehr ausgeliefert. Builds, die dort ' +
                'auflösen, erhalten einen 404. Das Paket wird weiterhin ausgeliefert, nur nicht mehr dort.',
        );
    });

    it('states the consequence the customer arrives with', () => {
        expect(partlyLapsedNote({ shared: false, in_force: true, registries: [live, lapsed] })).toContain('404');
    });

    it('says the package is still delivered, without counting what is left', () => {
        // "Über Ihre anderen Registries" was plural over a remainder that is exactly one
        // whenever a customer has two registries and one of them has lapsed — the common
        // shape, and the same defect this sentence was rewritten to remove.
        expect(partlyLapsedNote({ shared: false, in_force: true, registries: [live, lapsed] })).toContain(
            'Das Paket wird weiterhin ausgeliefert, nur nicht mehr dort.',
        );
    });

    it('enumerates several lapsed registries in German', () => {
        const note = partlyLapsedNote({
            shared: false,
            in_force: true,
            registries: [live, lapsed, { name: 'archive', in_force: false }],
        });

        // The article moves with the count — German has no form that covers both, and
        // "In der Registry legacy und archive" would be the singular defect one word later.
        expect(note).toContain('In den Registries legacy und archive');
    });

    it('says nothing where every registry still serves the package', () => {
        expect(partlyLapsedNote({ shared: false, in_force: true, registries: [live] })).toBeNull();
    });

    it('says nothing for a package no registry serves any more', () => {
        // That row is lapsedNote()'s, and "über die übrigen Registries weiterhin verfügbar"
        // would be a false promise on it.
        expect(partlyLapsedNote({ shared: false, in_force: false, registries: [lapsed] })).toBeNull();
    });
});

describe('noteFor', () => {
    it('gives a package no registry serves any more the fully lapsed note', () => {
        expect(noteFor({ shared: false, in_force: false, registries: [{ name: 'ci', in_force: false }] })).toBe(lapsedNote());
    });

    it('gives a package one registry stopped serving the partial note', () => {
        const row = {
            shared: false,
            in_force: true,
            registries: [
                { name: 'ci', in_force: true },
                { name: 'legacy', in_force: false },
            ],
        };

        expect(noteFor(row)).toBe(partlyLapsedNote(row));
    });

    it('leaves a package every registry still serves without a note', () => {
        expect(noteFor({ shared: false, in_force: true, registries: [{ name: 'ci', in_force: true }] })).toBeNull();
    });
});

describe('SHARED_BADGE_TITLE', () => {
    it('names the operator as the source', () => {
        expect(SHARED_BADGE_TITLE).toContain('Betreiber');
    });

    it('does not call the assignment a Freigabe to this organization', () => {
        // `shared` is an organization-agnostic boolean on the package — nothing about it names
        // a recipient, and what brings the package into this portal is a registry assignment.
        // This console keeps "Freigabe" for the `shared` marking itself and never for an
        // assignment, which packageAssignment.ts depends on.
        //
        // toBe, not a pair of not.toContain: those are case-sensitive, and SharedBadge's own
        // default tooltip opens with "Freigegeben:" — so "Freigegeben: vom Betreiber für Sie
        // bereitgestellt." passed every negative assertion while putting the claim straight
        // back. On a constant the exact form costs nothing.
        expect(SHARED_BADGE_TITLE).toBe('Vom Betreiber bereitgestellt.');
    });
});
