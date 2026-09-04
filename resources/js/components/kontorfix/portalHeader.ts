/**
 * The portal header's decisions, kept out of the template so they can be measured.
 *
 * The same reason portalPackages.ts exists: the sentence a customer reads is a factual
 * claim, and German inside a `<template>` is the part of this codebase nothing can check.
 */

import { type PortalAreaPaths, type SwitchableOrganization } from '@/types';

/**
 * The banner an operator account sees while standing in a customer's portal.
 *
 * The second sentence says CONTENT, not affordances, and then names the one affordance that
 * is missing. An earlier draft promised the view "entspricht dem, was der Kunde sieht",
 * which stopped being true the moment the token form was hidden from operators — and a
 * banner that promises an identical view above a form the customer has and the reader does
 * not makes the hiding read as a bug. The promise that matters is the other direction: the
 * operator is never shown a ROSIER view than the customer's.
 *
 * Returned whole rather than assembled from fragments in the template: a sentence split
 * across markup can only be asserted in pieces, and a piece that still matches is not the
 * sentence still being right.
 */
export function operatorBannerNote(organizationName: string): string {
    return `Sie sehen das Portal von ${organizationName} als Betreiber. Diese Ansicht zeigt die Inhalte des Kunden; Tokens können Sie hier nicht erstellen.`;
}

/** One row of the switcher, in SearchableSelect's option shape. */
export interface SwitcherOption {
    value: string;
    label: string;
    disabled?: boolean;
}

/**
 * The switcher's rows: the viewer's own portals, plus the organization currently addressed
 * when that is not one of them.
 *
 * `switchable` is the viewer's OWN memberships, and the switcher's selected value is the
 * addressed slug — so for an operator standing in a customer's portal the selection matched
 * no row and the control rendered its "Bitte wählen" placeholder, as if nothing were open.
 * The addressed organization is prepended instead, disabled: it names where the viewer is
 * without offering a navigation that is already the current page, and it keeps the operator's
 * way back to their own portals in the same control rather than suppressing it.
 */
export function switcherOptions(switchable: SwitchableOrganization[], current: SwitchableOrganization): SwitcherOption[] {
    const options: SwitcherOption[] = switchable.map((o) => ({ value: o.slug, label: o.name }));

    return options.some((o) => o.value === current.slug) ? options : [{ value: current.slug, label: current.name, disabled: true }, ...options];
}

/**
 * Whether to render the organization switcher at all.
 *
 * A picker with one option is not a choice — it is a label that looks clickable. Asked of
 * the OPTIONS rather than of `switchable`, so the disabled current row counts: an operator
 * with one own portal gets a real choice (back to their own) out of a `switchable` of one.
 * The boundary is the whole rule, so it is here rather than as a `> 1` in a `v-if`.
 */
export function offersSwitcher(options: SwitcherOption[]): boolean {
    return options.length > 1;
}

/** One entry of the portal's area navigation. */
export interface PortalAreaLink {
    label: string;
    href: string;
    /** The area the viewer is currently in — exactly one entry carries it. */
    current: boolean;
}

/**
 * The portal's area navigation: the package list and the registries, in that order.
 *
 * SPEC §3 CALLS REGISTRIES "THE SECOND AREA", and for most of this branch it was an area with
 * no way in. Task 1 made `/c/{org}/registries` the landing page and pointed the sidebar there;
 * task 3 moved the landing page to the package list and repointed both sidebar entries at
 * `portal.packages.index`; nothing then linked the registries. The customer with an empty
 * package list — a registry handed over before anything is assigned to it — could not reach
 * the Composer, npm and pip snippets or the token form from the interface at all, and that is
 * the exact moment the portal exists for.
 *
 * IN THE HEADER rather than on the package list, because an area is not a row on another
 * area's page. The sidebar cannot hold it: it renders application-wide with no organization in
 * hand, which is why both of its portal entries point at `portal.home` and resolve the viewer's
 * own. The header is mounted by all four portal pages, so the entry point survives whatever the
 * landing page becomes next.
 *
 * The hrefs are the server's (`portal.areas`), never assembled here — see the comment on that
 * prop. The LABELS are German and therefore live in this module rather than in the template:
 * they are the words the customer navigates by, and German inside a `<template>` is the part of
 * this codebase nothing can check. They are the two headings the pages already carry, so the
 * navigation and the page a viewer lands on name the same thing.
 *
 * `current` is derived from the PATH alone, with everything from the first `?` or `#` cut off
 * in one statement. Both of the portal's list pages write their table state into the query
 * (`pkg_search`, `pkg_type`), so a navigation that read the whole URL would stop marking itself
 * the moment the customer typed in a search box — worse than one that never marked itself.
 *
 * Registries wins on a PREFIX, so the registry detail and the package detail below it — both
 * addressed under `/registries/…` — stay in the area they belong to; everything else is the
 * package list, which is what `/c/{org}` is. The prefix is also what leaves the truncation
 * measurable in exactly one shape, `/c/{org}/registries?…`: the package list has nothing to
 * match either way, and a detail URL keeps its `/` before the query.
 */
export function portalAreaLinks(areas: PortalAreaPaths, currentUrl: string): PortalAreaLink[] {
    const path = currentUrl.replace(/[?#].*$/, '').replace(/\/+$/, '');
    const inRegistries = path === areas.registries || path.startsWith(`${areas.registries}/`);

    return [
        { label: 'Pakete', href: areas.packages, current: !inRegistries },
        { label: 'Registries', href: areas.registries, current: inRegistries },
    ];
}
