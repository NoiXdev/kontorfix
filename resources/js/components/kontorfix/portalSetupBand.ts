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
     * Relative and ALREADY GERMAN — `Carbon::diffForHumans()` with an explicit `de` locale
     * on the server. It is pinned there rather than left to `app.locale`, which is `en` on
     * this instance and produced "2 hours ago" in the middle of a German sentence.
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
 * The three steps, in order. A constant rather than markup, because they are five German
 * sentences and this module is where German becomes checkable.
 *
 * They describe the ROUTE, not any one ecosystem: which tool applies is the second step's
 * own answer, and naming all four there is what keeps this band from needing to know the
 * organization's enabled types. `RegistrySetup` already asks that question, on the page
 * this band's button leads to.
 */
export const SETUP_STEPS: readonly PortalSetupStep[] = [
    { title: 'Token erstellen', detail: 'Ein Lese-Token genügt zum Installieren.' },
    { title: 'Werkzeug konfigurieren', detail: 'Composer, npm, pip oder Docker — je nachdem, was diese Registry führt.' },
    { title: 'Paket installieren', detail: 'Der Befehl steht auf jeder Paketseite.' },
];

/** The heading and lead of the expanded band, stated once for both of its states. */
const TITLE = 'Zugang einrichten';
const LEAD = 'Einmal pro Rechner. Danach installieren Sie aus dieser Registry wie aus jeder anderen.';

/**
 * The band, for a state and the token behind it.
 *
 * The `none` and `unused` notes are ORGANIZATION-WIDE — "Sie haben noch kein Zugriffstoken",
 * never "Für Intern haben Sie noch kein Token". The state is decided across every token of
 * the organization (spec §3.1: "a token of this organization has been used"), and a
 * per-registry sentence over an organization-wide answer would be a false claim the moment a
 * customer with two registries holds a token for one of them.
 *
 * `used` names the token and when it was last used, because that is what makes the collapsed
 * line worth its one line: it is the customer's confirmation that the credential their CI
 * runs on is still alive, and the name is the only part of it they can act on. The pieces are
 * optional all the same — `lastUsed` is a wire value, and a band that dropped to a bare
 * `undefined` in the middle of a sentence would be worse than one that says less.
 */
export function setupBand(state: PortalSetupState, lastUsed: PortalLastUsedToken | null): PortalSetupBand {
    if (state === 'used') {
        const suffix = lastUsed === null ? '' : ` · ${lastUsed.name} zuletzt genutzt ${lastUsed.used_at}`;

        return {
            collapsed: true,
            title: `Zugang eingerichtet${suffix}`,
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
                ? 'Sie haben noch kein Zugriffstoken.'
                : // The `unused` note does NOT name the token. The state is organization-wide,
                  // so a customer can hold several unused ones, and naming whichever the server
                  // happened to return would read as "this one" about an arbitrary pick.
                  'Sie haben bereits ein Zugriffstoken, es wurde aber noch nicht benutzt.',
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
