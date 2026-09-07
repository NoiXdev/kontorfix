import { describe, expect, it } from 'vitest';
import { dockerDomainNote, dockerNoRegistryMessage, dockerSetupSnippet, dockerStepTitle } from './dockerSetup';

describe('dockerStepTitle', () => {
    it('names the step', () => {
        expect(dockerStepTitle()).toBe('Docker einrichten');
    });
});

describe('dockerSetupSnippet', () => {
    it('builds the whole login/pull/tag/push block on a custom domain, with a real example repository', () => {
        // toBe, not toContain: the point of this module is that every word here is pinned,
        // not merely "some substring survived". A mutation that drops the blank line
        // between sections, or reorders the two sections, must turn this red — the plate
        // puts Herunterladen before Hochladen deliberately, because reading is what most
        // customers ever do and it is the half that needs no publish token.
        expect(dockerSetupSnippet('images.3b.de', '', 'meinapp')).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de -u token',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/meinapp:<tag>',
                '',
                '# Hochladen — braucht ein Publish-Token',
                'docker tag meinapp:<tag> images.3b.de/meinapp:<tag>',
                'docker push images.3b.de/meinapp:<tag>',
            ].join('\n'),
        );
    });

    it('addresses the instance host and the two slugs when the registry has no domain', () => {
        // The case the whole task exists for. Two properties, both of which a bare
        // toContain on one line would miss:
        //
        // 1. the namespace goes into the IMAGE reference, on all three of pull/tag/push;
        // 2. `docker login` gets the host WITHOUT the namespace — a login against
        //    `registry.3b.de/3b/intern` is not something a Docker client can do, and the
        //    local `docker tag` source keeps its bare name too. Only `-u token` follows the
        //    host, and that is a username, not part of the address.
        expect(dockerSetupSnippet('registry.3b.de', '3b/intern/', 'meinapp')).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login registry.3b.de -u token',
                '',
                '# Herunterladen',
                'docker pull registry.3b.de/3b/intern/meinapp:<tag>',
                '',
                '# Hochladen — braucht ein Publish-Token',
                'docker tag meinapp:<tag> registry.3b.de/3b/intern/meinapp:<tag>',
                'docker push registry.3b.de/3b/intern/meinapp:<tag>',
            ].join('\n'),
        );
    });

    it('keeps the port in the host, which is the ordinary shape of a development instance', () => {
        expect(dockerSetupSnippet('localhost:8099', 'kunde/acme/', null)).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login localhost:8099 -u token',
                '',
                '# Herunterladen',
                'docker pull localhost:8099/kunde/acme/<repository>:<tag>',
                '',
                '# Hochladen — braucht ein Publish-Token',
                'docker tag <repository>:<tag> localhost:8099/kunde/acme/<repository>:<tag>',
                'docker push localhost:8099/kunde/acme/<repository>:<tag>',
            ].join('\n'),
        );
    });

    it('falls back to a placeholder repository when the registry has no docker package yet', () => {
        expect(dockerSetupSnippet('images.3b.de', '', null)).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de -u token',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/<repository>:<tag>',
                '',
                '# Hochladen — braucht ein Publish-Token',
                'docker tag <repository>:<tag> images.3b.de/<repository>:<tag>',
                'docker push images.3b.de/<repository>:<tag>',
            ].join('\n'),
        );
    });

    it('also falls back on an empty-string example, not only on null/undefined', () => {
        // A caller passing through a possibly-empty server value (rather than explicitly
        // null) must not produce `docker tag :<tag> host/:<tag>` — a blank repository name.
        // toBe, not toContain: the output here is byte-identical to the null case above, so
        // pinning the whole string was free — and toContain would still pass if the blank
        // repository name leaked into a part of the block this assertion did not check.
        expect(dockerSetupSnippet('images.3b.de', '', '')).toBe(
            [
                '# Anmelden — Benutzername beliebig, Passwort ist das Token',
                'docker login images.3b.de -u token',
                '',
                '# Herunterladen',
                'docker pull images.3b.de/<repository>:<tag>',
                '',
                '# Hochladen — braucht ein Publish-Token',
                'docker tag <repository>:<tag> images.3b.de/<repository>:<tag>',
                'docker push images.3b.de/<repository>:<tag>',
            ].join('\n'),
        );
    });
});

describe('dockerDomainNote', () => {
    it('tells the operator where a hostname is added', () => {
        expect(dockerDomainNote('operator')).toBe(
            'Diese Registry hat noch keinen eigenen Hostnamen. Die Befehle oben funktionieren unverändert — ' +
                'ein eigener Hostname verkürzt die Adresse lediglich, weil Organisation und Registry dann nicht mehr ' +
                'im Repository-Namen stehen. Hostnamen unter Registry → Domains hinzufügen.',
        );
    });

    it('sends the customer to their contact instead, and names no page they cannot open', () => {
        expect(dockerDomainNote('customer')).toBe(
            'Diese Registry hat noch keinen eigenen Hostnamen. Die Befehle oben funktionieren unverändert — ' +
                'ein eigener Hostname verkürzt die Adresse lediglich, weil Organisation und Registry dann nicht mehr ' +
                'im Repository-Namen stehen. Einen eigenen Hostnamen richtet Ihr Ansprechpartner ein.',
        );
    });

    it('never points the customer at the console', () => {
        // The one property the two whole-string assertions above state only implicitly, and
        // the reason this function takes an audience at all: /admin is a URL space a portal
        // account gets 403 from, and "Registry → Domains" is a page it has no route to.
        expect(dockerDomainNote('customer')).not.toContain('Domains');
        expect(dockerDomainNote('customer')).not.toContain('Registry →');
    });

    it('says nothing about images being impossible without a domain', () => {
        // The sentence this module used to carry ("Ohne eigene Domain kein Image-Betrieb")
        // became false with path addressing. Pinned as an absence so it cannot be
        // reintroduced by someone restoring the old copy from git history.
        for (const audience of ['operator', 'customer'] as const) {
            expect(dockerDomainNote(audience)).not.toContain('kein Image-Betrieb');
            expect(dockerDomainNote(audience)).not.toContain('nicht ansprechen');
        }
    });
});

describe('dockerNoRegistryMessage', () => {
    it('explains the one case that genuinely has no address', () => {
        expect(dockerNoRegistryMessage()).toBe(
            'Dieses Repository ist keiner sichtbaren Registry zugeordnet. Erst die Zuordnung zu einer Registry ' +
                'gibt ihm eine Adresse, unter der ein Docker-Client es ansprechen kann.',
        );
    });
});
