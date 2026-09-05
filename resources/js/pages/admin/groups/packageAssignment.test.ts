import { describe, expect, it } from 'vitest';
import {
    availabilityLabel,
    availabilityNote,
    availabilityOf,
    endsImmediately,
    expiryConsequence,
    formatDay,
    immediateWithdrawalNote,
    type NameHolding,
} from './packageAssignment';

/*
 * The cases are read off the two rules this module describes, not off its branches:
 *
 *  1. An assignment with a date is in force or lapsed, and the SERVER decides which. The
 *     same date therefore has to produce opposite answers depending on `in_force` — which
 *     is why every case below fixes the date and varies only that flag. A classifier that
 *     re-derived the state from the date would pass a suite that only ever paired a past
 *     date with `in_force: false`, and would then disagree with the registry at the
 *     boundary — the exact defect this column was surfaced to fix.
 *  2. An expiry does not release the name. Both texts an operator reads before and after a
 *     date passes must say so; a text that says only "afterwards it is no longer available"
 *     is the plausible, shorter, WRONG wording, so it is asserted against directly.
 */

describe('availabilityOf', () => {
    it('calls an assignment without a date open-ended', () => {
        expect(availabilityOf({ available_until: null, in_force: true })).toEqual({ state: 'permanent', day: null });
    });

    it('takes the in-force answer from the server, not from the date', () => {
        // One date, both answers. Nothing about '2026-12-31' decides this.
        expect(availabilityOf({ available_until: '2026-12-31', in_force: true }).state).toBe('limited');
        expect(availabilityOf({ available_until: '2026-12-31', in_force: false }).state).toBe('lapsed');
    });

    it('keeps the day in both states', () => {
        expect(availabilityOf({ available_until: '2026-12-31', in_force: true }).day).toBe('31.12.2026');
        expect(availabilityOf({ available_until: '2026-12-31', in_force: false }).day).toBe('31.12.2026');
    });
});

describe('formatDay', () => {
    it('renders the German day order', () => {
        expect(formatDay('2026-12-31')).toBe('31.12.2026');
    });

    it('does not shift the day across a timezone', () => {
        // Parsed as UTC midnight and formatted locally, this date is the 31st in Berlin and
        // the 30th west of Greenwich. Pure string surgery has no such failure mode, and this
        // pins it: a `new Date(day)` rewrite would go red on any machine behind UTC.
        expect(formatDay('2026-01-01')).toBe('01.01.2026');
        expect(formatDay('2026-12-31T23:59:59+00:00')).toBe('31.12.2026');
    });
});

describe('availabilityLabel', () => {
    it('names the day for a limited assignment', () => {
        expect(availabilityLabel({ state: 'limited', day: '31.12.2026' })).toBe('bis 31.12.2026');
    });

    it('says a lapsed assignment has lapsed, and when', () => {
        // Not merely "abgelaufen": the date is what turns "why is this failing?" into an
        // answer, and it is the only place the operator sees it once the row is out of force.
        expect(availabilityLabel({ state: 'lapsed', day: '31.12.2026' })).toBe('abgelaufen am 31.12.2026');
    });

    it('says an open-ended assignment is open-ended', () => {
        expect(availabilityLabel({ state: 'permanent', day: null })).toBe('unbefristet');
    });
});

/*
 * Every text below is asserted in BOTH ownership cases, because the whole hazard is a
 * sentence that is true in one and destructive in the other.
 *
 * A shared package owned elsewhere is suppressed by its assignment alone, so detaching
 * releases the name. A package this registry's organization owns is suppressed by ownership
 * (packageExistsLocally() clause 1), so detaching releases nothing and the operator has
 * destroyed an assignment and still gets a 404. Both notes render for every row of the
 * table — own packages are the majority of them — so a suite that only ever checked the
 * shared wording would pass the exact copy this round removed.
 */

/** The organization already holds this name through one of its own packages: detaching does
 *  not release it. Composer, so the type-naming half of the sentence is exercised too. */
const OWN: NameHolding = { ownedByRegistryOrg: true, type: 'composer', typeLabel: 'Composer' };
/** Shared and held elsewhere: the assignment is the only thing holding the name. */
const SHARED_ELSEWHERE: NameHolding = { ownedByRegistryOrg: false, type: 'composer', typeLabel: 'Composer' };
/** The ecosystem whose resolver matches PEP 503-normalised names. */
const OWN_PYTHON: NameHolding = { ownedByRegistryOrg: true, type: 'python', typeLabel: 'Python' };

describe('availabilityNote', () => {
    it('has nothing to add for an open-ended assignment', () => {
        expect(availabilityNote({ state: 'permanent', day: null }, OWN)).toBeNull();
        expect(availabilityNote({ state: 'permanent', day: null }, SHARED_ELSEWHERE)).toBeNull();
    });

    it('uses one word for one concept', () => {
        // "belegt" and "reserviert" for the same state, two sentences apart, is the drift
        // this module keeps being corrected for, at the vocabulary level. The elision that
        // was fixed alongside it is grammar and is left to review; this one is a decision.
        for (const holding of [OWN, OWN_PYTHON]) {
            expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, holding)).toContain('belegt');
            expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, holding)).not.toContain('reserviert');
        }
    });

    it('names the ecosystem the name is held in', () => {
        // The rule is per (type, name): an npm package of this name does not hold a Composer
        // name. Told only "ein Paket dieser Organisation", the operator has no way to find
        // the row that is holding it.
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).toContain('Composer-Paket');
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN_PYTHON)).toContain('Python-Paket');
    });

    it('warns for Python that the spelling of the holding name may differ', () => {
        // The case the existential fix exists for: the holder is `Shared_Lib` and the
        // operator is looking at `shared-lib`. Without this they search, find nothing under
        // the name in front of them, and conclude the message is wrong.
        const python = availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN_PYTHON);

        expect(python).toContain('Groß-/Kleinschreibung');
        expect(python).toContain('Shared_Lib und shared-lib sind derselbe Name');

        // Not said where it is not true: Composer and npm resolve the stored name verbatim.
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).not.toContain('Groß-/Kleinschreibung');
    });

    it('never claims the organization owns the NAME, only a package of that name', () => {
        // The rule is an existence question over (type, name) within the organization.
        // "Der Name gehört der Organisation" overstates it and stops being true the moment
        // the holding package is a different row from the one being described.
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).not.toContain('Der Name gehört');
        expect(expiryConsequence(OWN)).not.toContain('Der Name gehört');
        expect(immediateWithdrawalNote(OWN)).not.toContain('Der Name gehört');
    });

    it('does not make the operator the subject of the delivering', () => {
        // "Verlängern Sie die Zuweisung, um wieder auszuliefern" reads as the operator doing
        // the delivering; the German infinitive clause takes the main clause's subject.
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).not.toContain('um wieder auszuliefern');
        // …and it resumes something stopped, so `wieder aufnehmen`, not `fortsetzen`, which
        // continues something still running. This note only ever renders on a lapsed row.
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).toContain('um die Auslieferung wieder aufzunehmen');
        expect(availabilityNote({ state: 'lapsed', day: '31.12.2026' }, OWN)).not.toContain('fortzusetzen');
    });

    it('says the same thing about delivery in both cases', () => {
        // The half that does not depend on ownership: the registry stops serving and the
        // request is not passed upstream. Losing it for one of the two would leave that
        // operator with no explanation for the 404 at all.
        for (const holding of [OWN, SHARED_ELSEWHERE]) {
            for (const state of ['limited', 'lapsed'] as const) {
                const note = availabilityNote({ state, day: '31.12.2026' }, holding);

                expect(note).toContain('31.12.2026');
                expect(note).toContain('404');
                expect(note).toContain('nicht an den Upstream');
            }
        }
    });

    it('tells the operator to detach only when detaching would release the name', () => {
        for (const state of ['limited', 'lapsed'] as const) {
            expect(availabilityNote({ state, day: '31.12.2026' }, SHARED_ELSEWHERE)).toContain(
                'Entfernen Sie die Zuweisung, um den Namen freizugeben.',
            );
        }
    });

    it('tells the operator for an own package that detaching would NOT release the name', () => {
        // The destructive instruction this round removed: where the organization already
        // carries a package of this name, removing the assignment frees nothing, and the
        // name comes free only once no package of that organization carries it.
        for (const state of ['limited', 'lapsed'] as const) {
            const note = availabilityNote({ state, day: '31.12.2026' }, OWN);

            expect(note).not.toContain('Entfernen Sie die Zuweisung, um den Namen freizugeben.');
            expect(note).toContain('gibt ihn nicht frei');
            // What the rule actually is: the organization holds the name through A PACKAGE
            // of that name, which need not be this row — so the release condition is stated
            // over the packages, not over this assignment.
            expect(note).toContain('durch ein Composer-Paket dieser Organisation belegt');
            expect(note).toContain('kein Composer-Paket dieser Organisation diesen Namen mehr trägt');
        }
    });

    it('never calls the upstream by a single vendor name', () => {
        // The registry's upstream URL is configurable and has its own tab on this page, so
        // naming Packagist as the destination would be wrong as often as it was right.
        // expiryConsequence() names it once, explicitly as an example.
        for (const holding of [OWN, SHARED_ELSEWHERE]) {
            for (const state of ['limited', 'lapsed'] as const) {
                expect(availabilityNote({ state, day: '31.12.2026' }, holding)).not.toContain('Packagist');
            }
        }
    });
});

describe('expiryConsequence', () => {
    it('says the date does not reopen the upstream, in both cases', () => {
        for (const holding of [OWN, SHARED_ELSEWHERE]) {
            expect(expiryConsequence(holding)).toContain('404');
            expect(expiryConsequence(holding)).toContain('nicht an den Upstream');
        }
    });

    it('carries the ecosystem and the Python spelling warning too', () => {
        // Same sentence, same two facts — the editor's note is where an operator reads this
        // BEFORE acting, so losing either half here is worse than losing it in the row note.
        expect(expiryConsequence(OWN_PYTHON)).toContain('Python-Paket');
        expect(expiryConsequence(OWN_PYTHON)).toContain('Shared_Lib und shared-lib sind derselbe Name');
        expect(expiryConsequence(OWN)).toContain('Composer-Paket');
    });

    it('says what an empty date field means', () => {
        expect(expiryConsequence(SHARED_ELSEWHERE)).toContain('unbefristet');
    });

    it('offers detaching as the release path only where it is one', () => {
        expect(expiryConsequence(SHARED_ELSEWHERE)).toContain('um den Namen freizugeben');
        expect(expiryConsequence(OWN)).not.toContain('um den Namen freizugeben');
        expect(expiryConsequence(OWN)).toContain('gibt ihn nicht frei');
    });
});

describe('endsImmediately', () => {
    it('is true only for a day that is already over', () => {
        // The direction, stated over one fixed "today": yesterday takes effect at once,
        // today does not (the stored value is the END of today) and neither does tomorrow.
        expect(endsImmediately('2026-06-30', '2026-07-01')).toBe(true);
        expect(endsImmediately('2026-07-01', '2026-07-01')).toBe(false);
        expect(endsImmediately('2026-07-02', '2026-07-01')).toBe(false);
    });

    it('is false for an empty field, which means no date at all', () => {
        expect(endsImmediately('', '2026-07-01')).toBe(false);
    });

    it('compares whole days, not months or years, across a boundary', () => {
        expect(endsImmediately('2025-12-31', '2026-01-01')).toBe(true);
        expect(endsImmediately('2026-01-02', '2025-12-31')).toBe(false);
    });
});

describe('immediateWithdrawalNote', () => {
    it('does not call this a withdrawal of a "Freigabe"', () => {
        // In this console "Freigabe" is the `shared` marking itself
        // (Admin\PackageController::shared, admin/system/Index.vue). This note renders on
        // every row, and most of them were never shared, so the word would be simply false.
        for (const holding of [OWN, SHARED_ELSEWHERE]) {
            expect(immediateWithdrawalNote(holding)).not.toContain('Freigabe');
        }
    });

    it('says delivery stops at once and the name still does not reach the upstream', () => {
        for (const holding of [OWN, SHARED_ELSEWHERE]) {
            expect(immediateWithdrawalNote(holding)).toContain('sofort');
            expect(immediateWithdrawalNote(holding)).toContain('nicht an den Upstream');
        }
    });

    it('distinguishes withdrawing from detaching where they differ', () => {
        // The two ways to stop delivering. Only one of them keeps the name suppressed, and
        // an operator who confuses them opens the very fallthrough spec §4 closes.
        expect(immediateWithdrawalNote(SHARED_ELSEWHERE)).toContain('Entfernen Sie die Zuweisung, um den Namen freizugeben.');
    });

    it('does not offer detaching as a release path for an own package', () => {
        expect(immediateWithdrawalNote(OWN)).not.toContain('um den Namen freizugeben');
        expect(immediateWithdrawalNote(OWN)).toContain('gibt ihn nicht frei');
    });
});
