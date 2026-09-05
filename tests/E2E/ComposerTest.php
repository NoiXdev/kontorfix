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

// The install test's script marks each blob of output it cares about with a delimiter line
// rather than relying on brace-counting: composer.json and vendor/composer/installed.json
// are both read off the same stdout stream, and installed.json's own braces would break any
// attempt to find "the" first/last JSON object once two blobs are concatenated.
function extractBetween(string $haystack, string $start, string $end): string
{
    $startPos = strpos($haystack, $start) + strlen($start);
    $endPos = strpos($haystack, $end, $startPos);

    return trim(substr($haystack, $startPos, $endPos - $startPos));
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
    // repositories.packagist.org is disabled for the same reason UpstreamTest.php's own
    // fallthrough test disables it: without this line Composer still queries Packagist for
    // metadata on every `require`, even though the package it wants is right here — a slow
    // or unreachable Packagist would turn this run red for a reason that has nothing to do
    // with the registry. npm and pip have no equivalent default-registry fallback to turn
    // off; Composer does, and spec decision 3 ("the standard run depends on nothing outside
    // the stack") only held for two of the three ecosystems until this line existed here too.
    //
    // The content evidence is read off disk, not asked of Composer: `composer show
    // --format=json` reports Composer's own resolved-repository bookkeeping — the same
    // metadata it already trusted while resolving the dependency. A bug that made
    // ComposerMetadataBuilder and the installer self-consistently agree on name and version
    // while the dist extracted wrongly or was empty would still pass that check. NpmTest.php
    // and PypiTest.php don't have this hole: npm reads package.json out of the installed
    // tarball, and PyPI imports the installed module and prints its real __version__ — both
    // look at bytes the archive actually produced. The fixture ships a real file, src/Demo.php,
    // for exactly this: `cat` the installed composer.json for the name, and confirm
    // src/Demo.php exists, so this test checks what actually landed in vendor/, not what
    // Composer believes it installed.
    //
    // That is not the whole story, though: neither check tells dist from git apart. Stripping
    // `dist` from ComposerMetadataBuilder's output left Composer falling back to the `source`
    // entry and cloning straight from git://gitserver/demo.git — reachable from this very
    // container — so src/Demo.php was present either way and both checks above passed. The
    // dist path is the registry's actual product: the zip it builds, stores and serves: a
    // package.json/… check that also passes when the registry stops serving dists entirely
    // is not testing the registry, it is testing that a git server is reachable. Two more
    // assertions below cover the two halves of that: the registry side (the p2 metadata
    // advertises a dist, and that dist URL is actually servable) and the client side
    // (vendor/composer/installed.json — Composer's own bookkeeping, but here that is the
    // right source, since only Composer knows which transport it actually chose).
    $script = <<<SH
        set -e
        rm -rf /work/proj && mkdir -p /work/proj && cd /work/proj
        export COMPOSER_AUTH='{"http-basic":{"app:8080":{"username":"x","password":"{$context['read_token']}"}}}'
        composer init -n --name=kontorfix-e2e/consumer > /dev/null
        composer config secure-http false
        composer config repositories.packagist.org false
        composer config repositories.kontorfix composer {$context['base_url']}
        composer require {$context['composer_package']}:^1.0 --no-interaction --no-progress
        echo ===MANIFEST===
        cat vendor/{$context['composer_package']}/composer.json
        echo ===MANIFEST-END===
        test -f vendor/{$context['composer_package']}/src/Demo.php && echo DEMO_PHP_PRESENT || echo DEMO_PHP_MISSING
        echo ===INSTALLED===
        cat vendor/composer/installed.json
        echo ===INSTALLED-END===
        SH;

    $process = E2eStack::exec('client-composer', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $output = $process->getOutput();
    $manifest = json_decode(extractBetween($output, '===MANIFEST===', '===MANIFEST-END==='), true);
    $installed = json_decode(extractBetween($output, '===INSTALLED===', '===INSTALLED-END==='), true);

    expect($manifest['name'])->toBe($context['composer_package'])
        ->and($output)->toContain('DEMO_PHP_PRESENT');

    // Registry side: the p2 metadata must actually advertise a dist, and that exact dist.url
    // must actually be servable. There is only ever one tagged version in this fixture, so
    // index [0] is the version this whole test installs.
    $metadata = E2eStack::get("/p2/{$context['composer_package']}.json", $context['read_token']);
    expect($metadata['status'])->toBe(200);

    $versions = json_decode($metadata['body'], true)['packages'][$context['composer_package']] ?? [];
    $distUrl = $versions[0]['dist']['url'] ?? null;
    expect($distUrl)->not->toBeNull();

    $distFetch = E2eStack::get(E2eStack::pathFromRegistryUrl($distUrl), $context['read_token']);
    expect($distFetch['status'])->toBe(200);

    // Client side: which transport Composer actually chose for THIS package. Only Composer's
    // own bookkeeping can answer that — it is the right source here, unlike the content
    // checks above, because the question is precisely what installed.json exists to record.
    $installedEntry = null;
    foreach ($installed['packages'] ?? [] as $package) {
        if (($package['name'] ?? null) === $context['composer_package']) {
            $installedEntry = $package;
            break;
        }
    }

    // Not the composer.json check above: the fixture's manifest carries no "version" field
    // at all (checked — there is nothing to assert there), so the version has to come from
    // installed.json, right next to installation-source. Composer records it in the raw
    // tag form (composerTagVersion()), same as the p2 "version" field this file already
    // compares against `v1.0.0` for the same reason. What this catches: the client having
    // recorded a version other than the fixture's one tag — e.g. a proxied upstream copy
    // resolving under the same package name, a live possibility here since the seeder
    // configures a Packagist upstream unconditionally — or a registry advertising some
    // non-tag version string. What it does NOT catch: content-versus-label drift, such as a
    // tag-mapping bug that built the dist from `main` while still labelling it `v1.0.0` —
    // every value compared here, on both the registry and the client side, would read
    // `v1.0.0` regardless, and this assertion would stay green.
    expect($installedEntry)->not->toBeNull()
        ->and($installedEntry['installation-source'] ?? null)->toBe('dist')
        ->and($installedEntry['version'] ?? null)->toBe(composerTagVersion($context['version']));
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
        composer config repositories.packagist.org false
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
