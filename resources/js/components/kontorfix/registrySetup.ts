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

/**
 * Which setup steps to render, given the ecosystems the organization MAY serve
 * (`RegistryTypeService::effectiveFor()`, sent as the `types` prop).
 *
 * THERE IS NO FALLBACK HERE, and that absence is the point. The component used to read
 * `types.length ? types : ['composer', 'npm', 'python']`, written while the prop meant "the
 * types of the packages present" — where an empty list was merely a young registry. The
 * prop now means "the types this organization is PERMITTED to serve", and an empty list is
 * a definite answer: nothing. Substituting three ecosystems for it printed Composer,
 * auth.json, npm, pip and twine instructions for a registry whose every endpoint answers
 * 404 through EnsureRegistryTypeEnabled — the same defect as offering a Docker snippet
 * against a switched-off OCI endpoint, which RegistrySetupTest already pins on the server
 * side. An organization with `enabled_registry_types = []` is a state the console accepts
 * and stores, so this is reachable, not theoretical.
 *
 * Generic over the step shape so the step table itself can stay in the component next to
 * the markup that renders it; all this function owns is the selection rule.
 */
export function stepsForEcosystems<T extends { eco: string }>(steps: readonly T[], types: readonly string[]): T[] {
    return steps.filter((step) => types.includes(step.eco));
}

/**
 * What the Einrichtung tab says when the selection above comes back empty: the honest
 * answer, in place of instructions that would not work.
 *
 * One string for both audiences, unlike dockerDomainNote() — that one splits because its
 * operator half names Registry → Domains, a console page a portal account gets 403 from.
 * This sentence names no page at all, so there is nothing for a customer to be sent to in
 * vain and nothing an operator would be told twice.
 */
export function noEcosystemMessage(): string {
    return (
        'Für diese Registry ist derzeit kein Paket-Typ freigeschaltet. Einrichtungs-Schritte finden Sie hier, ' +
        'sobald mindestens ein Typ freigegeben ist — bis dahin beantwortet die Registry jede Client-Anfrage mit 404.'
    );
}
