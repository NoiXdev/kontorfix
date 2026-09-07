import { describe, expect, it } from 'vitest';
import { dockerEmptyStateMessage, dockerSetupSnippet, dockerStepTitle } from './dockerSetup';

describe('dockerStepTitle', () => {
    it('names the step', () => {
        expect(dockerStepTitle()).toBe('Docker einrichten');
    });
});

describe('dockerSetupSnippet', () => {
    it('builds the whole login/tag/push/pull block from the host, with a real example repository', () => {
        // toBe, not toContain: the point of this module is that every word here is pinned,
        // not merely "some substring survived". A mutation that drops the blank line
        // between sections, or swaps `push` and `pull`, must turn this red.
        expect(dockerSetupSnippet('images.3b.de', 'meinapp')).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de',
                '',
                '# Hochladen',
                'docker tag meinapp:<tag> images.3b.de/meinapp:<tag>',
                'docker push images.3b.de/meinapp:<tag>',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/meinapp:<tag>',
            ].join('\n'),
        );
    });

    it('falls back to a placeholder repository when the registry has no docker package yet', () => {
        expect(dockerSetupSnippet('images.3b.de', null)).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de',
                '',
                '# Hochladen',
                'docker tag <repository>:<tag> images.3b.de/<repository>:<tag>',
                'docker push images.3b.de/<repository>:<tag>',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/<repository>:<tag>',
            ].join('\n'),
        );
    });

    it('also falls back on an empty-string example, not only on null/undefined', () => {
        // A caller passing through a possibly-empty server value (rather than explicitly
        // null) must not produce `docker tag :<tag> host/:<tag>` — a blank repository name.
        // toBe, not toContain: the output here is byte-identical to the null case above, so
        // pinning the whole string was free — and toContain would still pass if the blank
        // repository name leaked into a part of the block this assertion did not check.
        expect(dockerSetupSnippet('images.3b.de', '')).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de',
                '',
                '# Hochladen',
                'docker tag <repository>:<tag> images.3b.de/<repository>:<tag>',
                'docker push images.3b.de/<repository>:<tag>',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/<repository>:<tag>',
            ].join('\n'),
        );
    });
});

describe('dockerEmptyStateMessage', () => {
    it('names the reachable path and says a Docker client cannot use it', () => {
        expect(dockerEmptyStateMessage('/r/3b/intern')).toBe(
            'Ohne eigene Domain kein Image-Betrieb. Diese Registry ist unter /r/3b/intern erreichbar — ' +
                'das genügt für Composer, npm und Python, aber ein Docker-Client kann sie so nicht ansprechen. ' +
                'Hostnamen unter Registry → Domains hinzufügen.',
        );
    });
});
