/**
 * The organization-wide Einrichtung tab's own German copy (Task 7), kept out of Setup.vue's
 * `<template>` for the same reason portalPackages.ts and portalHeader.ts exist: the sentence a
 * customer reads is a factual claim about what this page is and does, and German inside a
 * template is the part of this codebase nothing can check.
 */

/**
 * The page's intro, above RegistrySetup. It states the one thing that makes this tab a
 * DIFFERENT surface from `registries.show`'s own Einrichtung tab rather than a duplicate of
 * it: one address and one token reach every registry the organization has, but each registry
 * still decides publishing for itself — a publish token minted here is admin/maintainer-gated
 * exactly as the per-registry one is (RegistryTokenPolicy::create), and PypiController::upload()
 * and its siblings still resolve an upload against a single group's assignments, never against
 * the organization as a whole.
 */
export function setupIntro(): string {
    return 'Eine Quelle für alle Registries der Organisation — nur Lesen; veröffentlicht wird je Registry.';
}

/**
 * The warning shown beside this tab's mint form, where `registries.show`'s own token form
 * needs none: a token minted on THAT page carries `group_id`, so its reach is the one registry
 * the reader is looking at. A token minted here carries none at all — RegistryToken::issue()
 * writes `group_id = null` — so it resolves against EVERY registry of the organization,
 * including a collection group (`portal_enabled = false`) that never appears in this portal at
 * all. Without this sentence, a reader who has only ever seen the per-registry form has no way
 * to learn that the scope changed.
 */
export function orgTokenScopeWarning(): string {
    return 'Gilt für alle Registries der Organisation, auch nicht im Portal sichtbare.';
}

/**
 * The heading over this tab's own revoke table — a SEPARATE list from RegistrySetup's
 * "Vorhandene Tokens" dropdown (which never shows a value or offers revocation at all).
 * Named explicitly enough not to be mistaken for `registries.show`'s own "Zugriffstokens"
 * tab: that one lists tokens scoped to a single registry, and this list is org-wide tokens
 * only — the two never share a row.
 */
export function orgTokensHeading(): string {
    return 'Ihre organisationsweiten Tokens';
}

/** DataTable's empty-state message for the table `orgTokensHeading()` titles. */
export function orgTokensEmptyMessage(): string {
    return 'Noch keine organisationsweiten Tokens erstellt.';
}

/** The confirmation shown before DELETE — the same question `registries.show`'s own
 *  "Zugriffstokens" tab asks before revoking a per-registry token, word for word: revoking
 *  is revoking, whatever the token's scope. */
export function revokeConfirmation(): string {
    return 'Token wirklich widerrufen?';
}
