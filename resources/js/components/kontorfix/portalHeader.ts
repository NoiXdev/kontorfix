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
 * Returned whole rather than assembled from fragments in the template: a sentence split
 * across markup can only be asserted in pieces, and a piece that still matches is not the
 * sentence still being right.
 */
export function operatorBannerNote(organizationName: string): string {
    return `Sie sehen das Portal von ${organizationName} als Betreiber. Diese Ansicht entspricht dem, was der Kunde sieht.`;
}

/**
 * Whether to render the organization switcher at all.
 *
 * A picker with one option is not a choice — it is a label that looks clickable. The
 * boundary is the whole rule, so it is here rather than as a `> 1` in a `v-if`.
 */
export function offersSwitcher(switchable: SwitchableOrganization[]): boolean {
    return switchable.length > 1;
}

/** The switchable organizations as SearchableSelect options, in the order given. */
export function switcherOptions(switchable: SwitchableOrganization[]): { value: string; label: string }[] {
    return switchable.map((o) => ({ value: o.slug, label: o.name }));
}
