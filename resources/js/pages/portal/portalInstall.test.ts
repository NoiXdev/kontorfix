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
    installHeading,
    prerequisiteNote,
    readmeFallbackNote,
    setupLinkLabel,
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

    it('gives a lapsed row the reason and no command at all', () => {
        // The literal single-registry sentence, not `registryLapsedNote()` called here: a test
        // that compares the module's output with the module's own source asserts nothing.
        expect(installCell({ in_force: false, install: null })).toEqual({
            command: null,
            text:
                'Diese Registry liefert das Paket nicht mehr aus. Builds, die hier auflösen, erhalten ' +
                'einen 404. Wenden Sie sich an den Betreiber, wenn Sie das Paket weiter benötigen.',
        });
    });

    it('withholds a command that arrived on a lapsed row anyway', () => {
        // Defence in depth. The server sends null and `in_force` false together today; if a
        // command ever arrives beside a false flag, the cell must still refuse to offer it —
        // it is a command that answers 404.
        expect(installCell({ in_force: false, install: 'composer require 3b/altmodul' }).command).toBe(null);
    });

    it('refuses to print an empty command cell when the command is missing', () => {
        // The mirror case: `in_force` true with no command. Rendering an empty cell would
        // drop the explanation silently, which is the one outcome worse than either.
        expect(installCell({ in_force: true, install: null }).command).toBe(null);
    });
});
