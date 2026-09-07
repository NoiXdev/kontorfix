/**
 * The Docker access step's German copy (RegistrySetup's "Docker einrichten" step, plate 1
 * of the approved mockups) — the one string-building logic this project can actually unit
 * test, because there is no component runner here. `SetupSnippetBuilder.php` supplies only
 * raw facts (the registry's own host, or null; the /r/… path; an example repository name,
 * or null) — never the finished text. Every word a customer reads lives here instead, so a
 * wording mistake shows up as a red test rather than only in a screenshot nobody takes.
 *
 * The empty state matters as much as the snippet: Docker refuses a path prefix as part of
 * the registry host (the OCI Distribution Spec requires `/v2/` at the root), so a registry
 * with no row in `domains` cannot serve images at all, however it addresses Composer, npm
 * and Python. `dockerEmptyStateMessage()` is what tells an operator that BEFORE a `docker
 * push` fails on them, not after.
 */

const REPOSITORY_PLACEHOLDER = '<repository>';
const TAG_PLACEHOLDER = '<tag>';

/** The step's title, shown in RegistrySetup's card header next to the copy button. */
export function dockerStepTitle(): string {
    return 'Docker einrichten';
}

/**
 * The `docker login` / `tag` / `push` / `pull` block, addressed at the registry's own host
 * — never at the `/r/{organisation}/{registry}` path every registry also has, since that
 * form is exactly what a Docker client cannot reach (see the module doc comment).
 *
 * `exampleRepository` mirrors the convention `SetupSnippetBuilder::npmLines()` already
 * follows for npm's scope line: a real name drawn from what already exists in the registry
 * where one exists, so the copy-pasted commands work unmodified, and a generic placeholder
 * otherwise — never a fabricated name that only looks like a real one.
 */
export function dockerSetupSnippet(host: string, exampleRepository?: string | null): string {
    const repository = exampleRepository && exampleRepository.length > 0 ? exampleRepository : REPOSITORY_PLACEHOLDER;

    return [
        '# Anmelden — Benutzername beliebig, Passwort ist das Token',
        `docker login ${host}`,
        '',
        '# Hochladen',
        `docker tag ${repository}:${TAG_PLACEHOLDER} ${host}/${repository}:${TAG_PLACEHOLDER}`,
        `docker push ${host}/${repository}:${TAG_PLACEHOLDER}`,
        '',
        '# Herunterladen',
        `docker pull ${host}/${repository}:${TAG_PLACEHOLDER}`,
    ].join('\n');
}

/**
 * Shown in place of the snippet when the registry carries no row in `domains`. Named after
 * the actual reachable address (`registryPath`, the `/r/…` form) so the reader is told
 * exactly what they DO have, not only what is missing.
 */
export function dockerEmptyStateMessage(registryPath: string): string {
    return (
        `Ohne eigene Domain kein Image-Betrieb. Diese Registry ist unter ${registryPath} erreichbar — ` +
        'das genügt für Composer, npm und Python, aber ein Docker-Client kann sie so nicht ansprechen. ' +
        'Hostnamen unter Registry → Domains hinzufügen.'
    );
}
