/**
 * Everything the portal SAYS around an install command: which word heads the section, the one
 * line naming the setup the command assumes, and what a row of the package list shows where
 * the command would be.
 *
 * THE COMMAND ITSELF IS NOT BUILT HERE. `SetupSnippetBuilder::installCommand()` builds it, on
 * `RegistryUrl`, because it needs the registry's address — and a second assembly of it in the
 * browser is exactly the shape this whole task removed: `PackageType::installHint()` was a
 * second source, and its Python answer sent customers to PyPI. This module receives the
 * finished string and never edits it, with the single, explicit exception of
 * `abbreviateCommand()`, which shortens it FOR DISPLAY ONLY — the clipboard always gets what
 * the server sent.
 *
 * It exists at all for the reason `portalPackages.ts`, `portalSetupBand.ts` and
 * `dockerSetup.ts` give: there is no component runner on this frontend, so a sentence left
 * inside a `.vue` file is a sentence nothing verifies. Every German word a customer reads
 * about how to obtain a package is here, where a wording mistake is a red test rather than a
 * screenshot nobody takes.
 */
import { registryLapsedNote } from './portalPackages';

/**
 * The four ecosystems, as `PackageType` spells them.
 *
 * A union rather than `string`, and the full set rather than the `'composer' | 'npm'` that
 * `portal/Package.vue` declared until now: Python was already missing from that page's type
 * while Python packages were being served through it, so nothing type-checked the very branch
 * that was printing the wrong command.
 */
export type PortalPackageType = 'composer' | 'npm' | 'python' | 'docker';

/**
 * The section heading above the command.
 *
 * "Bezug" for a Docker repository, from plate 4 of the approved mockups. Not a synonym chosen
 * for variety: nothing is installed by a `docker pull` — the image is fetched, and it is what
 * a container runtime runs rather than what a project depends on. "Installation" over a pull
 * command would be the first of several small lies the rest of the page then has to keep up.
 */
export function installHeading(type: PortalPackageType): string {
    return type === 'docker' ? 'Bezug' : 'Installation';
}

/** The heading INSIDE the command card, beside the copy button — the same distinction. */
export function installCardTitle(type: PortalPackageType): string {
    return type === 'docker' ? 'Image beziehen' : 'Paket installieren';
}

/**
 * The one line above the command, naming what it assumes (plate 4).
 *
 * IT IS THE POINT OF THE LINE THAT IT IS SPECIFIC FOR DOCKER. Composer, npm and pip each read
 * a configuration file the setup tab writes, and "eine eingerichtete Registry" is the honest
 * name for all three at once. A Docker client has no such file to be handed: what stands
 * between the reader and a working `docker pull` is one `docker login` against one host, and
 * naming that host is the difference between a sentence the reader can act on and a sentence
 * that sends them looking.
 *
 * `dockerHost` is `RegistryUrl::dockerHost()` — host and port, no scheme and no namespace,
 * because that is what `docker login` takes. It is deliberately NOT the registry's `url`,
 * which carries `https://` and, on the instance host, the `/r/{org}/{registry}` path: a
 * Docker client accepts neither.
 */
export function prerequisiteNote(type: PortalPackageType, dockerHost: string): string {
    return type === 'docker' ? `Setzt docker login ${dockerHost} voraus.` : 'Setzt eine eingerichtete Registry voraus.';
}

/**
 * The link beside that line, and the same label plate 5 puts above the package list.
 *
 * It NAMES the registry rather than saying "Einrichtung öffnen", because a customer with
 * several registries has to know which one they are about to configure — the setup differs
 * per registry (different address, different token), so an unqualified label is the one word
 * that would let someone configure the wrong one.
 */
export function setupLinkLabel(registryName: string): string {
    return `Einrichtung für ${registryName} öffnen`;
}

/**
 * What stands in for a missing README.
 *
 * Type-aware because the old single sentence promised two things a Docker repository does not
 * have: "Installationsbefehle" under a section headed "Bezug", and a "Versionshistorie" that
 * is empty for every Docker repository — tags are `oci_tags` rows, and nothing writes a
 * `package_versions` row on the OCI push path.
 */
export function readmeFallbackNote(type: PortalPackageType): string {
    return type === 'docker'
        ? 'Für dieses Repository liegt keine README vor. Der Bezugsbefehl steht unten.'
        : 'Für dieses Paket liegt keine README vor. Installationsbefehle stehen unten, die Versionshistorie darunter.';
}

/**
 * The command as a TABLE CELL shows it — the long one shortened, everything else untouched.
 *
 * Only the pip command is long, and only because it carries the index URL that is the entire
 * point of it. Plate 5 draws that cell as `pip install --index-url … kernmodul`: the flag
 * stays, so the reader can see the command is registry-aware, and the package name stays,
 * because it is what they are scanning the column for. The URL is what goes.
 *
 * The rule is "any argument that is a URL", not a length cut-off, so the elision lands in the
 * same place for every registry rather than moving with the length of an organization's slug.
 * A `docker pull` reference is untouched by construction: an image reference carries no
 * scheme, which is exactly what makes it an image reference.
 *
 * DISPLAY ONLY. `installCell()` hands the whole command to the clipboard alongside this, and
 * the abbreviated form must never reach it — a `pip install --index-url … kernmodul` pasted
 * into a terminal fails, and a reader who fixes it by deleting the `--index-url` is back to
 * installing from PyPI.
 */
export function abbreviateCommand(command: string): string {
    return command
        .split(' ')
        .map((part) => (/^https?:\/\//.test(part) ? '…' : part))
        .join(' ');
}

/** What `installCell()` needs of a package row, as `Portal\RegistryController::show()` sends it. */
export interface InstallCellRow {
    /** REGISTRY-LOCAL: whether THIS registry still serves the assignment. */
    in_force: boolean;
    /** The whole command, or null — the server withholds it for a lapsed assignment. */
    install: string | null;
}

/** The two halves of an Installation cell: what is shown, and what a copy button would copy. */
export interface InstallCell {
    /**
     * The whole command for the clipboard, or null when there is none to offer. Null is also
     * what tells the page to render the text as an explanation rather than as a command.
     */
    command: string | null;
    /** The cell's own text: the abbreviated command, or the reason there is none. */
    text: string;
}

/**
 * One row's Installation cell (plate 5).
 *
 * A LAPSED ASSIGNMENT GETS THE REASON INSTEAD OF A COMMAND — the same rule
 * `portal/Package.vue` follows, for the same reason: the command that would go here answers
 * 404, and a snippet that 404s is worse than none. `registryLapsedNote()` is the sentence for
 * a page addressed by ONE registry (see portalPackages.ts, TWO ANSWERS); the landing page's
 * `lapsedNote()` claims none of the customer's registries serve the package, which this page
 * cannot know.
 *
 * The reason is rendered here, in place of the command, and NOT additionally as a note under
 * the row. It used to be that note, back when the row had nothing else to give up; printing
 * both would say the same sentence twice on one row, and the column the reader is scanning
 * for an answer is where the answer belongs.
 *
 * `install === null` is treated exactly like `in_force === false` rather than trusted to
 * follow from it. The two come from one server-side condition today, and this function is the
 * one place the page decides whether it has a command — a null slipping through as an empty
 * command cell would silently drop the explanation.
 */
export function installCell(row: InstallCellRow): InstallCell {
    if (!row.in_force || row.install === null) {
        return { command: null, text: registryLapsedNote() };
    }

    return { command: row.install, text: abbreviateCommand(row.install) };
}
