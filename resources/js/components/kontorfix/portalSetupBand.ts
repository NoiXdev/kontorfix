/**
 * The entry band on the portal's landing page: which of its two shapes to render, and every
 * German sentence in either of them.
 *
 * The module exists for the reason `portalPackages.ts` and `portalHeader.ts` give: this
 * frontend has no component runner, so a decision or a sentence left inside a `.vue` file is
 * a decision and a sentence nothing verifies. Both halves of this band are exactly that kind
 * of thing — the collapse rule is the only logic on the page, and the copy is the first text
 * a customer reads about how to reach their packages at all.
 *
 * THE COLLAPSE RULE IS THE SERVER'S ANSWER, NOT THIS MODULE'S. `Portal\PackageController`
 * reads `registry_tokens.last_used_at` and sends the finished `setupState`; this module maps
 * that state onto what the band says. Re-deriving the state here from `lastUsed !== null`
 * would be a second statement of the rule in the one place it cannot see the data — and the
 * two would then disagree the first time the server's definition of a live token changes.
 * `lastUsed` is display payload for the collapsed line and nothing else.
 */

/**
 * The three states, as `Portal\PackageController::index()` sends them.
 *
 * - `none` — this organization holds no usable token at all.
 * - `unused` — it holds one, but nothing has ever authenticated with it.
 * - `used` — a token of this organization has been used, so the customer is connected.
 */
export type PortalSetupState = 'none' | 'unused' | 'used';

/** The token behind the `used` state, for the one line that names it. */
export interface PortalLastUsedToken {
    name: string;
    /**
     * Relative and ALREADY GERMAN — `Carbon::diffForHumans()` on the server, with Carbon's
     * locale pinned to `de` application-wide in `AppServiceProvider`. It is pinned there
     * rather than left to `app.locale`, which is `en` on this instance and produced
     * "2 hours ago" in the middle of a German sentence.
     */
    used_at: string;
}

/** One registry of the picker, as the payload's `registries` entries carry it. */
export interface PortalSetupRegistry {
    id: string;
    name: string;
    /** The registry's address — `RegistryUrl::base()`, the one source for it. */
    url: string;
}

/** One of the three numbered steps in the expanded band. */
export interface PortalSetupStep {
    title: string;
    detail: string;
}

/**
 * What the band renders. FLAT, with nulls for the fields the collapsed shape has no use for,
 * rather than a discriminated union: the template narrows a union behind a `computed` only
 * unreliably under `vue-tsc`, and a band that silently stops type-checking is a worse trade
 * than four fields two of which are null in one of the two shapes.
 */
export interface PortalSetupBand {
    /** The one-line shape — the state that matters, and the reason the band exists. */
    collapsed: boolean;
    /** The heading of the expanded band, or the whole sentence of the collapsed one. */
    title: string;
    /** The line under the heading. Null when collapsed. */
    lead: string | null;
    /** What the customer's token situation is, under the steps. Null when collapsed. */
    note: string | null;
    /** The label on the way into the selected registry's Einrichtung tab. */
    action: string;
}

/**
 * The three steps, in order, for the ecosystems the organization MAY serve
 * (`RegistryTypeService::effectiveFor()`, as the labels of those types).
 *
 * A FUNCTION rather than a constant, and that is the whole of finding F3: the second step
 * used to name "Composer, npm, pip oder Docker" from plate 1 whatever the organization was
 * permitted to use, and its button then leads to the Einrichtung tab — which since task 3
 * shows only the permitted ecosystems, or `noEcosystemMessage()` when there are none.
 * Naming four ecosystems and then showing one is the same false claim that task existed to
 * remove, one page earlier.
 *
 * It takes LABELS, not type values: `PackageType` owns the spelling of every ecosystem
 * ("npm" lowercase, "Python" not "pip"), the console reads it through `useRegistryTypes()`,
 * and a second table of names here is how two surfaces end up spelling one ecosystem two
 * ways. The caller maps; this module only writes the sentence.
 */
export function setupSteps(ecosystems: readonly string[]): PortalSetupStep[] {
    return [
        { title: 'Token erstellen', detail: 'Ein Lese-Token genügt zum Installieren.' },
        { title: 'Werkzeug konfigurieren', detail: toolDetail(ecosystems) },
        { title: 'Paket installieren', detail: 'Der Befehl steht auf jeder Paketseite.' },
    ];
}

/**
 * The second step's sentence, over the ecosystems the organization may serve.
 *
 * Three shapes, because two of them are claims the general one would get wrong. With
 * NOTHING permitted there is no tool to configure at all — the same state
 * `noEcosystemMessage()` answers on the page this band leads to, and a list of zero names
 * joined into "Für  — je nachdem" would be a sentence with a hole in it. With ONE the
 * "je nachdem" is false: there is nothing to choose between, and saying so is the more
 * useful answer than naming a single ecosystem as if it were a menu.
 */
function toolDetail(ecosystems: readonly string[]): string {
    if (ecosystems.length === 0) {
        return 'Für Ihre Organisation ist derzeit kein Paket-Typ freigeschaltet.';
    }

    if (ecosystems.length === 1) {
        return `Für ${ecosystems[0]} — den einzigen Paket-Typ, den Ihre Organisation nutzen darf.`;
    }

    const list = `${ecosystems.slice(0, -1).join(', ')} oder ${ecosystems[ecosystems.length - 1]}`;

    return `Für ${list} — je nachdem, was diese Registry führt.`;
}

/**
 * The caption above the registry picker.
 *
 * Here rather than inline in the template, for the reason every other string in this band is
 * here: a sentence left in a `.vue` file is a sentence nothing in this project can read, and
 * one string kept out of the module means the file has two rules about where copy lives.
 */
export const REGISTRY_PICKER_LABEL = 'Registry';

/** The heading and lead of the expanded band, stated once for both of its states. */
const TITLE = 'Zugang einrichten';
const LEAD = 'Einmal pro Rechner. Danach installieren Sie aus dieser Registry wie aus jeder anderen.';

/**
 * The band, for a state and the token behind it.
 *
 * EVERY SENTENCE IS ABOUT THE ORGANIZATION, never about the reader — "Ihre Organisation
 * hat …", not "Sie haben …". The state is decided across every token of the organization
 * (spec §3.1: "a token of this organization has been used"), while `Portal\RegistryController
 * ::show()` filters the setup page's token list by `user_id`. A colleague who holds no token
 * of their own is therefore inside the `used` state, and "Sie haben ein Zugriffstoken" would
 * tell that person something the very next page contradicts: an empty token list that does
 * not know the named token at all. Naming the owner keeps the two pages saying one thing.
 *
 * `none` says "kein gültiges" and not "noch kein": the server maps an EXISTING token that was
 * revoked or has expired to `none` too (PortalSetupBandTest pins both), so a sentence claiming
 * the organization never had one is false for exactly the customer whose build has just
 * started failing.
 *
 * `used` names the token and when it was last used, because that is what makes the collapsed
 * line worth its one line: it is the confirmation that the credential the organization's CI
 * runs on is still alive, and the name is the only part of it anyone can act on.
 */
export function setupBand(state: PortalSetupState, lastUsed: PortalLastUsedToken | null): PortalSetupBand {
    // `lastUsed !== null` is TypeScript's half of this condition, not a second state:
    // `PackageController::index()` derives both fields from one row and sends `used` exactly
    // when the token is populated, so there is no payload the two halves disagree about. It
    // is written as a guard rather than a non-null assertion because an assertion would be a
    // claim about the wire that nothing checks; the fall-through — the band asking for setup
    // — is the safe answer if that ever stopped holding.
    if (state === 'used' && lastUsed !== null) {
        return {
            collapsed: true,
            title: `Zugang eingerichtet · Ihre Organisation hat ${lastUsed.name} zuletzt ${lastUsed.used_at} genutzt`,
            lead: null,
            note: null,
            // "ansehen", not "öffnen": nothing here is outstanding any more, and a verb that
            // asks for an action would put the wallpaper back one word at a time.
            action: 'Einrichtung ansehen',
        };
    }

    return {
        collapsed: false,
        title: TITLE,
        lead: LEAD,
        note:
            state === 'none'
                ? 'Ihre Organisation hat derzeit kein gültiges Zugriffstoken.'
                : // The `unused` note does NOT name the token. The state is organization-wide,
                  // so an organization can hold several unused ones, and naming whichever the
                  // server happened to return would read as "this one" about an arbitrary pick.
                  'Ihre Organisation hat bereits ein Zugriffstoken, es wurde aber noch nicht benutzt.',
        action: 'Einrichtung öffnen',
    };
}

/**
 * Whether the band renders at all.
 *
 * A portal-enabled organization with no portal-visible registry is a reachable state — the
 * switch is per registry (`groups.portal_enabled`) and an organization can have no groups at
 * all. The band would then have nothing to point at: its button builds
 * `portal.registries.show` from a registry id it does not have, which is a Ziggy exception on
 * the customer's landing page rather than a missing sentence. The rule is here rather than as
 * a `v-if` on a length, because it is the one thing standing between that state and a broken
 * page.
 */
export function offersSetupBand(registries: readonly PortalSetupRegistry[]): boolean {
    return registries.length > 0;
}

/**
 * Whether to offer the registry picker.
 *
 * The same rule `offersSwitcher()` states for the portal header, for the same reason: a
 * picker with one option is not a choice, it is a label that looks clickable. With one
 * registry the band names it in plain text instead.
 */
export function offersRegistryPicker(registries: readonly PortalSetupRegistry[]): boolean {
    return registries.length > 1;
}

/**
 * The registry the band is currently about — the picked one, or the first when the picked id
 * matches nothing.
 *
 * The fallback is not decoration. The selection is browser state and the list is a prop, so
 * an Inertia partial reload that drops a registry from the list leaves the selection pointing
 * at a row that is gone; reading `.url` or `.id` off `undefined` there is a blank band and a
 * broken button. Callers must check `offersSetupBand()` first — with an empty list there is
 * no registry to name and this returns null.
 */
export function selectedRegistry(registries: readonly PortalSetupRegistry[], selectedId: string): PortalSetupRegistry | null {
    return registries.find((registry) => registry.id === selectedId) ?? registries[0] ?? null;
}
