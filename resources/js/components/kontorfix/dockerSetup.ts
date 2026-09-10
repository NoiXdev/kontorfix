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
 * One row of the organization-wide Docker list — SetupSnippetBuilder::forOrganization()'s
 * `dockerGroups` entries verbatim, each self-consistent (its own `dockerHost` paired with
 * its own `dockerRepositoryPrefix`; see that method's doc comment for why a domain-bound
 * group cannot be paired with the shared instance host).
 */
export interface OrgDockerGroup {
    name: string;
    slug: string;
    /** Whether this registry appears in the portal — false labels it "Sammlung" below. */
    portal_enabled: boolean;
    dockerHost: string;
    dockerRepositoryPrefix: string;
}

/**
 * The heading over one group's block in the organization-wide Docker section: its name, or
 * its name plus "(Sammlung)" for a registry the portal itself does not list.
 *
 * "Sammlung" (collection), not "versteckt" (hidden) or omitting the row outright: the org-wide
 * token reaches this registry exactly as it reaches a portal-visible one (Task 6's builder
 * lists it for that reason), so a reader relying only on this tab must be told it exists and
 * what to call it — an omitted row would be a registry the org-wide token can push to and this
 * page never mentions.
 */
export function dockerOrgGroupLabel(group: Pick<OrgDockerGroup, 'name' | 'portal_enabled'>): string {
    return group.portal_enabled ? group.name : `${group.name} (Sammlung)`;
}

/**
 * The organization-wide Docker step's whole content: one heading plus one login/pull/tag/push
 * block per group, in the order the server already sorted them (by name).
 *
 * PER-GROUP, unlike dockerSetupSnippet() alone: the org endpoint serves no Docker traffic of
 * its own (SetupSnippetBuilder::forOrganization()'s doc comment states why — an image
 * reference under `/o/{slug}` would be ambiguous about which registry it names), so there is
 * no single command this could print. Every entry gets dockerSetupSnippet() called with ITS
 * OWN host and prefix — never the shared top-level `dockerHost` paired with another entry's
 * prefix, which is exactly the defect a review caught in the builder before this ever
 * rendered (see forOrganization()'s doc comment).
 *
 * No example repository is passed through: `dockerGroups` carries no `dockerExample` field at
 * all (only for(Group) derives one, from that one registry's own packages), so every block
 * falls back to the `<repository>` placeholder dockerSetupSnippet() already produces for a
 * registry with none yet.
 */
export function dockerOrgSetupSnippet(groups: OrgDockerGroup[]): string {
    return groups
        .map((g) => `# ${dockerOrgGroupLabel(g)}\n${dockerSetupSnippet(g.dockerHost, g.dockerRepositoryPrefix)}`)
        .join('\n\n');
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
