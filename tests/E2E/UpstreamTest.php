<?php

use Tests\E2E\Support\E2eStack;

/**
 * The only tests in this suite that depend on anything outside the stack, which is exactly
 * why they are separated: the default run must never be red because npmjs was slow.
 *
 * Skipped with a stated reason rather than silently absent — a test that vanishes when an
 * environment variable is unset is indistinguishable from one that was deleted.
 */
beforeEach(function () {
    if (getenv('E2E_UPSTREAM') !== '1') {
        test()->markTestSkipped('E2E_UPSTREAM is not set — run `bin/e2e --upstream` to include the fallthrough tests.');
    }
});

it('falls through to Packagist for a package the registry does not hold', function () {
    $context = E2eStack::context();

    $script = <<<SH
        set -e
        rm -rf /work/up && mkdir -p /work/up && cd /work/up
        export COMPOSER_AUTH='{"http-basic":{"app:8080":{"username":"x","password":"{$context['read_token']}"}}}'
        composer init -n --name=kontorfix-e2e/upstream > /dev/null
        composer config secure-http false
        composer config repositories.packagist.org false
        composer config repositories.kontorfix composer {$context['base_url']}
        composer require psr/log:^3.0 --no-interaction --no-progress
        ls vendor/psr/log/composer.json
        SH;

    $process = E2eStack::exec('client-composer', $script, 600);

    // `repositories.packagist.org false` is what makes this a real test: without it Composer
    // would reach Packagist directly and the registry's proxy would never be consulted.
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('vendor/psr/log/composer.json');
});

it('falls through to npmjs for a package the registry does not hold', function () {
    $context = E2eStack::context();
    // Reuse the one place that holds the nerf-dart derivation, not a second copy of it — see
    // E2eStack::npmAuthKey()'s own docblock for why a drifted copy fails silently (the token
    // is simply never sent) rather than with an error.
    $authKey = E2eStack::npmAuthKey();

    $script = <<<SH
        set -e
        rm -rf /work/up && mkdir -p /work/up && cd /work/up
        rm -f /root/.npmrc
        npm config set '{$authKey}' '{$context['read_token']}'
        npm config set registry '{$context['base_url']}'
        npm init -y > /dev/null
        npm install ms@2.1.3 --no-audit --no-fund
        cat node_modules/ms/package.json
        SH;

    $process = E2eStack::exec('client-npm', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $installed = json_decode(
        substr($process->getOutput(), (int) strpos($process->getOutput(), '{')),
        true,
    );

    // The registry is the ONLY configured registry here, so `ms` can only have arrived
    // through the proxy.
    expect($installed['name'])->toBe('ms')->and($installed['version'])->toBe('2.1.3');
});

it('falls through to PyPI for a package the registry does not hold', function () {
    $context = E2eStack::context();
    // Derived, not the literal `app:8080/r/e2e-customer/e2e-registry` PypiTest.php's own
    // scripts used to spell out by hand in three places — see E2eStack::pipIndexUrl().
    $indexUrl = E2eStack::pipIndexUrl($context['read_token']);

    $script = <<<SH
        set -e
        rm -rf /work/upvenv && python -m venv /work/upvenv
        /work/upvenv/bin/pip install --no-cache-dir --quiet \
            --index-url {$indexUrl} \
            --trusted-host app \
            six==1.16.0
        /work/upvenv/bin/python -c "import six; print(six.__version__)"
        SH;

    $process = E2eStack::exec('client-python', $script, 600);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // Equality on the final line, not a suffix match on the whole output: `toEndWith` would
    // also accept "21.16.0". This mirrors NpmTest.php's and PypiTest.php's own REFUSAL
    // tests, which use exactly this last-non-empty-line shape — not PypiTest.php's install
    // test, which gets away with a plain trim()->toBe() because a `--quiet` pip install
    // there leaves stdout to the final print alone; kept defensive here rather than
    // assuming that stays true for every future addition to this script.
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $process->getOutput())),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('1.16.0');
});
