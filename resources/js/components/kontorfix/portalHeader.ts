/**
 * The portal header's two decisions, kept out of the template so they can be measured.
 *
 * The same reason portalPackages.ts exists: the sentence a customer reads is a factual
 * claim, and German inside a `<template>` is the part of this codebase nothing can check.
 */

import { type SwitchableOrganization } from '@/types';

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
