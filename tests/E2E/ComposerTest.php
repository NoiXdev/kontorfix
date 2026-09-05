<?php

use Tests\E2E\Support\E2eStack;

// The git fixture tags its release `v1.0.0` (docker/e2e/gitserver/entrypoint.sh — the same
// tag tests/E2E/StackTest.php already asserts over git://), not `1.0.0`. Composer's p2
// "version" field carries the tag verbatim (ComposerMetadataBuilder emits `version_pretty`,
// which GitSourceImporter sets to the raw tag string, confirmed against the real registry
// response — `version_normalized` is the padded "1.0.0.0" form, and neither of those is bare
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
    //
    // The evidence is read off disk, not asked of Composer: `composer show --format=json`
    // reports Composer's own resolved-repository bookkeeping — the same metadata it already
    // trusted while resolving the dependency. A bug that made ComposerMetadataBuilder and
    // the installer self-consistently agree on name and version while the dist extracted
    // wrongly or was empty would still pass that check. NpmTest.php and PypiTest.php don't
    // have this hole: npm reads package.json out of the installed tarball, and PyPI imports
    // the installed module and prints its real __version__ — both look at bytes the archive
    // actually produced. The fixture ships a real file, src/Demo.php, for exactly this: `cat`
    // the installed composer.json for the name, and confirm src/Demo.php exists, so this test
    // checks what actually landed in vendor/, not what Composer believes it installed.
    $script = <<<SH
        set -e
        rm -rf /work/proj && mkdir -p /work/proj && cd /work/proj
        export COMPOSER_AUTH='{"http-basic":{"app:8080":{"username":"x","password":"{$context['read_token']}"}}}'
        composer init -n --name=kontorfix-e2e/consumer > /dev/null
        composer config secure-http false
        composer config repositories.kontorfix composer {$context['base_url']}
        composer require {$context['composer_package']}:^1.0 --no-interaction --no-progress
        cat vendor/{$context['composer_package']}/composer.json
        test -f vendor/{$context['composer_package']}/src/Demo.php && echo DEMO_PHP_PRESENT || echo DEMO_PHP_MISSING
        SH;

    $process = E2eStack::exec('client-composer', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $output = $process->getOutput();
    $manifest = json_decode(
        substr($output, (int) strpos($output, '{'), (int) strrpos($output, '}') - (int) strpos($output, '{') + 1),
        true,
    );

    expect($manifest['name'])->toBe($context['composer_package'])
        ->and($output)->toContain('DEMO_PHP_PRESENT');
});

it('refuses an anonymous composer read with 401 and installs nothing', function () {
    $context = E2eStack::context();

    $anonymous = E2eStack::get('/packages.json');

    expect($anonymous['status'])->toBe(401);

    // No COMPOSER_AUTH at all, and a fresh project directory, so a leftover token from the
    // previous test cannot make this pass for the wrong reason. COMPOSER_CACHE_DIR is also
    // fresh and separate from the install test's: client-composer is one container reused
    // across all three tests, so without this the dist the install test just pulled would
    // still be sitting in Composer's default cache when this one runs, and a cache hit could
    // rescue an anonymous install that should have been refused at the HTTP layer.
    // PypiTest.php isolates the equivalent case with its own PIP_CACHE_DIR.
    $script = <<<SH
        rm -rf /work/anon /work/anoncache && mkdir -p /work/anon
        export COMPOSER_CACHE_DIR=/work/anoncache
        cd /work/anon
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
