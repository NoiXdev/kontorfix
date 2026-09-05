<?php

use Tests\E2E\Support\E2eStack;

// The git fixture tags its release `v1.0.0` (docker/e2e/gitserver/entrypoint.sh — the same
// tag tests/E2E/StackTest.php already asserts over git://), not `1.0.0`. Composer's p2
// "version" field and `composer show`'s "versions" field both carry the tag verbatim
// (ComposerMetadataBuilder emits `version_pretty`, which GitSourceImporter sets to the raw
// tag string, confirmed against the real registry response and a real `composer show`
// output — `version_normalized` is the padded "1.0.0.0" form, and neither of those is bare
// "1.0.0"). $context['version'] stays the plain "1.0.0" used by npm and PyPI, which really
// do report an unprefixed version, so the "v" is added back only where a raw tag-derived
// field is being compared — not folded into $context itself, which would misrepresent what
// the other two ecosystems actually do.
function composerTagVersion(string $version): string
{
    return "v{$version}";
}

it('syncs the seeded package from the git daemon through the queued worker', function () {
    $context = E2eStack::context();

    // The whole chain hangs off this one wait: clone, tag scan, dist build, metadata. It
    // runs in the worker container, on the queue, exactly as it does in production.
    $versions = E2eStack::waitForComposerVersions($context['composer_package']);

    $reported = array_column($versions, 'version');

    expect($reported)->toContain(composerTagVersion($context['version']));
});

it('installs the synced package with the real composer client', function () {
    $context = E2eStack::context();

    // secure-http is off because the stack speaks plain HTTP; COMPOSER_AUTH carries the
    // token as HTTP Basic, which is the form AuthenticateRegistry accepts for Composer.
    $script = <<<SH
        set -e
        rm -rf /work/proj && mkdir -p /work/proj && cd /work/proj
        export COMPOSER_AUTH='{"http-basic":{"app:8080":{"username":"x","password":"{$context['read_token']}"}}}'
        composer init -n --name=kontorfix-e2e/consumer > /dev/null
        composer config secure-http false
        composer config repositories.kontorfix composer {$context['base_url']}
        composer require {$context['composer_package']}:^1.0 --no-interaction --no-progress
        composer show --format=json {$context['composer_package']}
        SH;

    $process = E2eStack::exec('client-composer', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $shown = json_decode(
        substr($process->getOutput(), (int) strpos($process->getOutput(), '{')),
        true,
    );

    expect($shown['name'])->toBe($context['composer_package'])
        ->and($shown['versions'])->toContain(composerTagVersion($context['version']));
});

it('refuses an anonymous composer read with 401 and installs nothing', function () {
    $context = E2eStack::context();

    $anonymous = E2eStack::get('/packages.json');

    expect($anonymous['status'])->toBe(401);

    // No COMPOSER_AUTH at all, and a fresh project directory, so a leftover token from the
    // previous test cannot make this pass for the wrong reason.
    $script = <<<SH
        rm -rf /work/anon && mkdir -p /work/anon && cd /work/anon
        unset COMPOSER_AUTH
        composer init -n --name=kontorfix-e2e/anon > /dev/null
        composer config secure-http false
        composer config repositories.kontorfix composer {$context['base_url']}
        composer require {$context['composer_package']}:^1.0 --no-interaction --no-progress || true
        ls vendor/kontorfix-e2e 2>/dev/null | wc -l
        SH;

    $process = E2eStack::exec('client-composer', $script, 600);

    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $process->getOutput())),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('0');
});
