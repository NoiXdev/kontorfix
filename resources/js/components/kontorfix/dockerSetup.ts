/**
 * The Docker access step's German copy (RegistrySetup's "Docker einrichten" step, plate 2
 * of the approved mockups) — the one string-building logic this project can actually unit
 * test, because there is no component runner here. `SetupSnippetBuilder.php` supplies only
 * raw facts (the registry's host, the namespace a repository name carries there, whether a
 * custom domain exists, an example repository name or null) — never the finished text.
 * Every word a customer reads lives here instead, so a wording mistake shows up as a red
 * test rather than only in a screenshot nobody takes.
 *
 * WHAT CHANGED, AND WHY THE EMPTY STATE IS GONE. This module used to carry a
 * `dockerEmptyStateMessage()`: "Ohne eigene Domain kein Image-Betrieb". That was true while
 * the OCI Distribution Spec's `/v2/` at the root of a host meant a registry needed a
 * hostname of its own before any Docker client could reach it. `ResolveOciContext` ended
 * it — the instance's own host answers `/v2/` too, with the organization and registry slugs
 * as the leading segments of the repository name. Every registry has a working Docker
 * address from the first minute, so there is no dead end left to describe, and the snippet
 * is never withheld. A custom domain is now only the SHORTER address, which is what
 * `dockerDomainNote()` says.
 */

const REPOSITORY_PLACEHOLDER = '<repository>';
const TAG_PLACEHOLDER = '<tag>';

/**
 * Who is reading. The two audiences get the same explanation and a different last
 * sentence: the operator can open Registry → Domains, and the customer cannot — a link to
 * a page one cannot open is worse than none.
 */
export type SetupAudience = 'operator' | 'customer';

/** The step's title, shown in RegistrySetup's card header next to the copy button. */
export function dockerStepTitle(): string {
    return 'Docker einrichten';
}

/**
 * The `docker login` / `pull` / `tag` / `push` block, in the order plate 2 of the approved
 * mockups puts them.
 *
 * THE ORDER IS PULL BEFORE PUSH, and it is the plate's choice rather than an accident:
 * reading is what most customers ever do, and it is the half that needs no publish token —
 * so the section that works with the token a reader already has comes first, and the one
 * that does not is labelled with what it additionally requires.
 *
 * `-u token` is on the login line because the username genuinely is arbitrary here (the
 * token is the password), so naming a fixed one spares the reader an interactive prompt
 * they would otherwise have to answer with something they had to invent.
 *
 * The tag stays `<tag>`. The plate shows a concrete `1.4.0`, which is a mockup's licence to
 * look real; a snippet the reader copies must not invent a version number that exists
 * nowhere in their registry.
 *
 * `host` and `repositoryPrefix` are the registry's address as `RegistryUrl` computes it —
 * a custom domain with an empty prefix, or the instance host with `{organisation}/{registry}/`
 * in front of the repository name. They are two fields rather than one joined string
 * because the `docker login` line takes the host WITHOUT the namespace: a login against
 * `host/org/registry` is not a thing a Docker client can do. (`-u token` follows the host
 * on that line, but that is a username, not part of the address.)
 *
 * `exampleRepository` mirrors the convention `SetupSnippetBuilder::npmLines()` already
 * follows for npm's scope line: a real name drawn from what already exists in the registry
 * where one exists, so the copy-pasted commands work unmodified, and a generic placeholder
 * otherwise — never a fabricated name that only looks like a real one.
 */
export function dockerSetupSnippet(host: string, repositoryPrefix: string, exampleRepository?: string | null): string {
    const repository = exampleRepository && exampleRepository.length > 0 ? exampleRepository : REPOSITORY_PLACEHOLDER;
    const image = `${host}/${repositoryPrefix}${repository}:${TAG_PLACEHOLDER}`;

    return [
        '# Anmelden — Benutzername beliebig, Passwort ist das Token',
        `docker login ${host} -u token`,
        '',
        '# Herunterladen',
        `docker pull ${image}`,
        '',
        '# Hochladen — braucht ein Publish-Token',
        `docker tag ${repository}:${TAG_PLACEHOLDER} ${image}`,
        `docker push ${image}`,
    ].join('\n');
}

/**
 * The note under the snippet on a registry that has no custom domain yet. Not shown at all
 * once one exists — there is nothing left to say then.
 *
 * It is a note and not a warning: the commands above it work as they stand. The two
 * audiences differ only in the last sentence, and only because they have different ways
 * out — see SetupAudience.
 */
export function dockerDomainNote(audience: SetupAudience): string {
    const shared =
        'Diese Registry hat noch keinen eigenen Hostnamen. Die Befehle oben funktionieren unverändert — ' +
        'ein eigener Hostname verkürzt die Adresse lediglich, weil Organisation und Registry dann nicht mehr ' +
        'im Repository-Namen stehen.';

    return audience === 'operator'
        ? `${shared} Hostnamen unter Registry → Domains hinzufügen.`
        : `${shared} Einen eigenen Hostnamen richtet Ihr Ansprechpartner ein.`;
}

/**
 * Shown in place of the snippet on the package-level Docker page when the repository is in
 * no registry this viewer can see — the one case where there is genuinely no address to
 * print, and the only reason that page still has a branch without commands at all.
 */
export function dockerNoRegistryMessage(): string {
    return (
        'Dieses Repository ist keiner sichtbaren Registry zugeordnet. Erst die Zuordnung zu einer Registry ' +
        'gibt ihm eine Adresse, unter der ein Docker-Client es ansprechen kann.'
    );
}
