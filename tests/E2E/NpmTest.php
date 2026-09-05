<?php

use Tests\E2E\Support\E2eStack;

/**
 * `npm publish` and `npm install` against a registry whose auth is a bearer token. The npm
 * config key is keyed by the registry's own path, so it carries the full base URL minus the
 * scheme.
 *
 * The registry URL is normalized to a trailing slash before deriving that key. npm's own
 * nerf-dart algorithm (`@npmcli/config`'s `nerfDart()`) resolves the credential key by
 * taking the URL's directory — `new URL('.', registry)` — so a registry URL without a
 * trailing slash treats its last path segment as a filename and drops it: the auth key
 * `npm config set` ends up keyed one path segment shorter than the URL npm actually requests
 * against, and every request is sent unauthenticated. Confirmed against the real client:
 * without the trailing slash, `npm publish` failed with ENEEDAUTH even though the token was
 * set under what looked like the matching key.
 */
function npmScript(string $body): string
{
    $context = E2eStack::context();
    $registry = rtrim($context['base_url'], '/').'/';
    $authKey = str_replace('http:', '', $registry).':_authToken';

    return <<<SH
        set -e
        npm config set '{$authKey}' '{TOKEN}'
        npm config set registry '{$registry}'
        {$body}
        SH;
}

it('publishes the fixture package with the real npm client', function () {
    $context = E2eStack::context();

    $script = str_replace('{TOKEN}', $context['publish_token'], npmScript(<<<'SH'
        rm -rf /work/pub && mkdir -p /work/pub && cp -R /fixtures/npm-package/. /work/pub/
        cd /work/pub && npm publish
        SH));

    $process = E2eStack::exec('client-npm', $script);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // Not the exit code alone: the registry must now serve the packument the client wrote.
    $packument = E2eStack::get('/'.$context['npm_package'], $context['read_token']);

    expect($packument['status'])->toBe(200)
        ->and(json_decode($packument['body'], true)['versions'])->toHaveKey($context['version']);
});

it('installs the published package with the real npm client', function () {
    $context = E2eStack::context();

    $script = str_replace('{TOKEN}', $context['read_token'], npmScript(<<<SH
        rm -rf /work/inst && mkdir -p /work/inst && cd /work/inst
        npm init -y > /dev/null
        npm install {$context['npm_package']}@{$context['version']} --no-audit --no-fund
        cat node_modules/{$context['npm_package']}/package.json
        SH));

    $process = E2eStack::exec('client-npm', $script);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $installed = json_decode(
        substr($process->getOutput(), (int) strpos($process->getOutput(), '{')),
        true,
    );

    expect($installed['name'])->toBe($context['npm_package'])
        ->and($installed['version'])->toBe($context['version']);
});

it('refuses an anonymous npm install with 401 and installs nothing', function () {
    $context = E2eStack::context();

    // Half one: the server said 401. A failing client command alone proves nothing — a typo
    // in the URL fails identically.
    $anonymous = E2eStack::get('/'.$context['npm_package']);

    expect($anonymous['status'])->toBe(401);

    // Half two: nothing landed on disk. The earlier tests wrote a token into /root/.npmrc,
    // so it must be removed here — without that, this test would exercise an authenticated
    // install and pass for the wrong reason.
    $script = <<<SH
        rm -rf /work/anon && mkdir -p /work/anon && cd /work/anon
        npm config delete '{$context['npm_package']}' > /dev/null 2>&1 || true
        rm -f /root/.npmrc
        npm init -y > /dev/null
        npm install {$context['npm_package']} --registry {$context['base_url']} --no-audit --no-fund || true
        ls node_modules 2>/dev/null | wc -l
        SH;

    $process = E2eStack::exec('client-npm', $script);

    expect(trim($process->getOutput()))->toEndWith('0');
});
