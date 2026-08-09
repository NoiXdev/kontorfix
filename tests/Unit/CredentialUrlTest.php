<?php

use App\Support\CredentialUrl;

it('replaces the userinfo component with a fixed marker', function () {
    expect(CredentialUrl::redact('https://svc:s3cr3t@nexus.corp/repository/npm-proxy'))
        ->toBe('https://***@nexus.corp/repository/npm-proxy');
    expect(CredentialUrl::redact('https://x:ghp_AAAABBBBCCCC@github.com/acme/private.git'))
        ->toBe('https://***@github.com/acme/private.git');
    expect(CredentialUrl::redact('https://ghp_AAAABBBBCCCC@github.com/acme/private.git'))
        ->toBe('https://***@github.com/acme/private.git');
    expect(CredentialUrl::redact('ssh://git@github.com/acme/private.git'))
        ->toBe('ssh://***@github.com/acme/private.git');
});

it('takes the last at-sign of the authority, so a password containing @ is not partly kept', function () {
    expect(CredentialUrl::redact('https://user:p@ss@mirror.corp/npm'))
        ->toBe('https://***@mirror.corp/npm');
});

it('leaves a url without userinfo untouched', function () {
    foreach ([
        'https://repo.packagist.org',
        'https://registry.npmjs.org/',
        'https://github.com/acme/public.git',
        // An @ after the authority belongs to the path, not to a credential.
        'https://cdn.example.test/dists/acme/demo@1.0.0.zip',
        'not a url at all',
        '',
    ] as $url) {
        expect(CredentialUrl::redact($url))->toBe($url);
    }

    expect(CredentialUrl::redact(null))->toBeNull();
});

it('removes the userinfo component outright, leaving no marker behind', function () {
    // strip() serves URLs that are *followed*, not displayed: a `Location` carrying
    // `***@` is a credential the client would dial, and it still announces that a
    // credential exists and on which host.
    expect(CredentialUrl::strip('https://svc:s3cr3t@nexus.corp/repository/npm-proxy'))
        ->toBe('https://nexus.corp/repository/npm-proxy')
        ->and(CredentialUrl::strip('https://user:p@ss@mirror.corp/npm'))->toBe('https://mirror.corp/npm')
        ->and(CredentialUrl::strip('https://***@nexus.corp/npm'))->toBe('https://nexus.corp/npm')
        ->and(CredentialUrl::strip('https://repo.packagist.org'))->toBe('https://repo.packagist.org')
        ->and(CredentialUrl::strip(null))->toBeNull();

    foreach (['https://svc:pw@a.test/x', 'https://***@a.test/x', 'https://tok@a.test/x'] as $url) {
        expect(CredentialUrl::strip($url))->not->toContain('@')
            ->and(CredentialUrl::strip($url))->not->toContain(CredentialUrl::MARKER);
    }
});

it('detects a userinfo component whether or not it is already redacted', function () {
    expect(CredentialUrl::carries('https://svc:s3cr3t@nexus.corp/npm'))->toBeTrue()
        ->and(CredentialUrl::carries('https://***@nexus.corp/npm'))->toBeTrue()
        ->and(CredentialUrl::carries('ssh://git@github.com/acme/x.git'))->toBeTrue()
        ->and(CredentialUrl::carries('https://nexus.corp/npm'))->toBeFalse()
        // An @ after the authority belongs to the path.
        ->and(CredentialUrl::carries('https://cdn.example.test/d/demo@1.0.0.zip'))->toBeFalse()
        ->and(CredentialUrl::carries(null))->toBeFalse();
});

it('recognises its own marker so a redacted value cannot be written back', function () {
    expect(CredentialUrl::isRedacted('https://***@nexus.corp/npm'))->toBeTrue();
    expect(CredentialUrl::isRedacted('ssh://***@github.com/acme/x.git'))->toBeTrue();

    expect(CredentialUrl::isRedacted('https://svc:s3cr3t@nexus.corp/npm'))->toBeFalse();
    expect(CredentialUrl::isRedacted('https://nexus.corp/npm'))->toBeFalse();
    expect(CredentialUrl::isRedacted(null))->toBeFalse();
});
