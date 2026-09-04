/**
 * RegistrySetup's two pieces of logic that are not markup.
 *
 * Extracted because these are the lines where a mistake reaches the CONSOLE and not only
 * the portal: the console renders this component without either prop, and reading a default
 * wrong there would hide the token form — or the publish ability — from every admin on
 * every registry page.
 */

/**
 * Whether the component may offer minting.
 *
 * `!== false` rather than `?? true`, and the distinction is the whole point: the prop is
 * optional, the console omits it entirely, and an omitted prop arrives as `undefined`. Only
 * an explicit `false` — which is what the portal passes for an account that may not mint —
 * turns the offer off.
 */
export function offersMinting(mayMint?: boolean): boolean {
    return mayMint !== false;
}

/**
 * Whether the component may offer the publish ability.
 *
 * The same optional-prop contract as offersMinting(), for the same reason and with the same
 * default. This form posts to the same route as the one on the portal's tokens tab, and
 * RegistryTokenPolicy::create() refuses Publish to a plain member — so offering it here
 * unconditionally was the shown-and-then-refused shape one file over from the one this task
 * removed, for the larger population: every member on the Einrichtung tab, refused at 403.
 */
export function offersPublishing(mayPublish?: boolean): boolean {
    return mayPublish !== false;
}
