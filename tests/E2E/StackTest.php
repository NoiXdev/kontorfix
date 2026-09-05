<?php

use Tests\E2E\Support\E2eStack;

it('answers the health endpoint on the published port', function () {
    $body = @file_get_contents(E2eStack::hostRoot().'/up');

    expect($body)->not->toBeFalse()
        ->and(json_decode((string) $body, true))->toBe(['status' => 'ok']);
});

it('advertises a metadata-url built on the address the client was given', function () {
    $context = E2eStack::context();

    // Spec §1: the harness must assert this before any install runs, because Composer
    // follows it (and the dist URLs it leads to) without complaint — a mismatch here does
    // not fail loudly, it makes the client resolve every subsequent request against the
    // wrong address. `metadata-url` is root-relative (Composer resolves it against the
    // origin of whichever repository URL configured it — `composer config
    // repositories.kontorfix composer {base_url}` in every other test in this suite), so
    // "points at the address the client was given" means: resolved against that origin, it
    // has to land on exactly base_url's own path plus `/p2/%package%.json` — not merely
    // that the key is present. Dist URLs are the only other place an address mismatch
    // could show, and E2eStack::pathFromRegistryUrl() deliberately discards the host before
    // comparing those, so this is the only check in the suite that would catch one.
    $packagesJson = E2eStack::get('/packages.json', $context['read_token']);

    expect($packagesJson['status'])->toBe(200);

    $body = json_decode($packagesJson['body'], true);
    $registryPath = (string) parse_url($context['base_url'], PHP_URL_PATH);

    expect($body['metadata-url'] ?? null)->toBe($registryPath.'/p2/%package%.json');
});

it('serves the composer fixture repository over git://, tag included', function () {
    $process = E2eStack::exec('client-composer', 'git ls-remote --tags git://gitserver/demo.git');

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('refs/tags/v1.0.0');
});
