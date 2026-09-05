<?php

use Tests\E2E\Support\E2eStack;

/**
 * `npm publish` and `npm install` against a registry whose auth is a bearer token. The auth
 * key itself comes from `E2eStack::npmAuthKey()` — see there for why it needs a trailing
 * slash on the registry URL to line up with what npm's nerf-dart algorithm looks up.
 */
function npmScript(string $body): string
{
    $context = E2eStack::context();
    $registry = rtrim($context['base_url'], '/').'/';
    $authKey = E2eStack::npmAuthKey();

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
        rm -f /root/.npmrc
        npm init -y > /dev/null
        npm install {$context['npm_package']} --registry {$context['base_url']} --no-audit --no-fund || true
        ls node_modules 2>/dev/null | wc -l
        SH;

    $process = E2eStack::exec('client-npm', $script);

    // Equality on the final line, not a suffix match on the whole output: `toEndWith('0')`
    // would also accept "10", "20" or "100" — a suffix match on a numeric string proves
    // nothing about the actual count. `ls | wc -l` is the last line npm's own chatter
    // leaves behind, so that line, trimmed, is compared for exact equality.
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $process->getOutput())),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('0');
});
