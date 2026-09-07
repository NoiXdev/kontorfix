/*
 * Every expectation here is a WHOLE string compared with `toBe`/`toEqual` against a literal.
 *
 * Nothing is asserted with `toContain`, and nothing is compared against a value imported from
 * the module under test. Both shortcuts pass for a module that returns the wrong thing
 * consistently — and the defect this whole change closes was exactly that: a command that
 * contained `pip install` and the package name, and pointed at PyPI.
 */
import { describe, expect, it } from 'vitest';
import {
    abbreviateCommand,
    installCardTitle,
    installCell,
    installCellLapsedNote,
    installHeading,
    prerequisiteNote,
    readmeFallbackNote,
    setupLinkLabel,
    versionsEmptyNote,
    versionsHeading,
} from './portalInstall';

describe('installHeading', () => {
    it('heads a docker repository with Bezug and everything else with Installation', () => {
        expect(installHeading('docker')).toBe('Bezug');
        expect(installHeading('composer')).toBe('Installation');
        expect(installHeading('npm')).toBe('Installation');
        expect(installHeading('python')).toBe('Installation');
    });
});

describe('installCardTitle', () => {
    it('keeps the same distinction inside the card', () => {
        expect(installCardTitle('docker')).toBe('Image beziehen');
        expect(installCardTitle('composer')).toBe('Paket installieren');
        expect(installCardTitle('python')).toBe('Paket installieren');
    });
});

describe('prerequisiteNote', () => {
    it('names the login host for a docker repository', () => {
        // The host is the whole value of the sentence: without it the reader is told a
        // command exists and not where to run it against.
        expect(prerequisiteNote('docker', 'images.3b.de')).toBe('Setzt docker login images.3b.de voraus.');
    });

    it('carries a port through, because an image reference is written host-and-port or not at all', () => {
        expect(prerequisiteNote('docker', 'reg.example.test:8443')).toBe('Setzt docker login reg.example.test:8443 voraus.');
    });

    it('says the same general thing for the three clients that read a configuration file', () => {
        // The host is deliberately ignored here — a `composer require` names no host, and a
        // sentence that printed one would describe a step that does not exist.
        expect(prerequisiteNote('composer', 'images.3b.de')).toBe('Setzt eine eingerichtete Registry voraus.');
        expect(prerequisiteNote('npm', 'images.3b.de')).toBe('Setzt eine eingerichtete Registry voraus.');
        expect(prerequisiteNote('python', 'images.3b.de')).toBe('Setzt eine eingerichtete Registry voraus.');
    });
});

describe('setupLinkLabel', () => {
    it('names the registry the link would configure', () => {
        expect(setupLinkLabel('Intern')).toBe('Einrichtung für Intern öffnen');
        expect(setupLinkLabel('Images')).toBe('Einrichtung für Images öffnen');
    });
});

describe('readmeFallbackNote', () => {
    it('promises a version history only where there is one', () => {
        expect(readmeFallbackNote('composer')).toBe(
            'Für dieses Paket liegt keine README vor. Installationsbefehle stehen unten, die Versionshistorie darunter.',
        );
        expect(readmeFallbackNote('docker')).toBe('Für dieses Repository liegt keine README vor. Der Bezugsbefehl steht unten.');
    });
});

describe('versionsHeading / versionsEmptyNote', () => {
    it('heads a docker repository with its tags, because it has no versions to head', () => {
        // An OCI push writes an `oci_tags` row and never a `package_versions` one, so the
        // section headed "Versionen" was empty for every Docker repository however many tags
        // had been pushed to it — and said so.
        expect(versionsHeading('docker')).toBe('Tags');
        expect(versionsHeading('composer')).toBe('Versionen');
        expect(versionsHeading('npm')).toBe('Versionen');
        expect(versionsHeading('python')).toBe('Versionen');
    });

    it('empties that list in the words of the thing that is missing', () => {
        expect(versionsEmptyNote('docker')).toBe('Noch keine Tags gepusht.');
        expect(versionsEmptyNote('composer')).toBe('Noch keine Versionen verfügbar.');
        expect(versionsEmptyNote('python')).toBe('Noch keine Versionen verfügbar.');
    });
});

describe('installCellLapsedNote', () => {
    it('is one short sentence, not the three the package page carries', () => {
        // Plate 5 draws exactly this in the Installation column. The long
        // `registryLapsedNote()` is 166 characters over three sentences; in a column sized for
        // a 35-character command, beside a Beschreibung column, it sets the height of the row
        // to repeat what the `abgelaufen` badge two columns left already says.
        expect(installCellLapsedNote()).toBe('Diese Registry liefert das Paket nicht mehr aus.');
    });
});

describe('abbreviateCommand', () => {
    it('elides the index url and keeps the flag and the package name', () => {
        expect(abbreviateCommand('pip install --index-url https://token:<token>@registry.3b.de/r/3b/intern/simple/ kernmodul')).toBe(
            'pip install --index-url … kernmodul',
        );
    });

    it('leaves a command without a url exactly as it is', () => {
        expect(abbreviateCommand('composer require 3b/kernmodul')).toBe('composer require 3b/kernmodul');
        expect(abbreviateCommand('npm install @3b/ui-kit')).toBe('npm install @3b/ui-kit');
        // An image reference carries no scheme, which is what keeps a `docker pull` intact
        // even though it does contain a host.
        expect(abbreviateCommand('docker pull registry.3b.de/3b/intern/meinapp:1.4.0')).toBe('docker pull registry.3b.de/3b/intern/meinapp:1.4.0');
    });

    it('elides a plain http url too', () => {
        expect(abbreviateCommand('pip install --index-url http://token:<token>@localhost:8080/simple/ kernmodul')).toBe(
            'pip install --index-url … kernmodul',
        );
    });
});

describe('installCell', () => {
    it('shows the abbreviated command and copies the whole one', () => {
        const cell = installCell({
            in_force: true,
            install: 'pip install --index-url https://token:<token>@registry.3b.de/r/3b/intern/simple/ kernmodul',
        });

        // Both halves in one object, asserted together: a cell whose clipboard carried the
        // abbreviated form would paste a command that fails, and a reader who repairs it by
        // deleting the `--index-url` is back to installing from PyPI.
        expect(cell).toEqual({
            command: 'pip install --index-url https://token:<token>@registry.3b.de/r/3b/intern/simple/ kernmodul',
            text: 'pip install --index-url … kernmodul',
        });
    });

    it('gives a lapsed row the short reason and no command at all', () => {
        // The literal, not `installCellLapsedNote()` called here: a test that compares the
        // module's output with the module's own source asserts nothing.
        expect(installCell({ in_force: false, install: null })).toEqual({
            command: null,
            text: 'Diese Registry liefert das Paket nicht mehr aus.',
        });
    });

    it('withholds a command that arrived on a lapsed row anyway', () => {
        // Defence in depth. The server sends null and `in_force` false together today; if a
        // command ever arrives beside a false flag, the cell must still refuse to offer it —
        // it is a command that answers 404.
        expect(installCell({ in_force: false, install: 'composer require 3b/altmodul' }).command).toBe(null);
    });

    it('says nothing at all when a command is missing from a row that is in force', () => {
        // The mirror case: `in_force` true with no command, unreachable today. It offers no
        // command — but it must not reach for the lapsed reason either, which is what it used
        // to print: that sentence is a claim about the assignment, and on this branch the
        // assignment is in force. Asserted as a whole object so the empty text is pinned
        // rather than merely unasserted.
        expect(installCell({ in_force: true, install: null })).toEqual({ command: null, text: '' });
    });
});
