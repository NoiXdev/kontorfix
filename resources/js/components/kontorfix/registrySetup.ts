/**
 * RegistrySetup's one piece of logic that is not markup.
 *
 * Extracted because it is the line where a mistake reaches the CONSOLE and not only the
 * portal: the console renders this component without the prop, and reading the default
 * wrong there would hide the token form from every admin on every registry page.
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
