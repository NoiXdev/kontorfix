<?php

use App\Jobs\SyncPackage;
use App\Models\Package;

it('stores the rendered readme when the repository has one', function () {
    $origin = makeGitRepoWith([
        'composer.json' => json_encode(['name' => 'acme/demo']),
        'README.md' => '# Hallo',
    ]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => null,
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->readme_html)->toContain('<h1>Hallo</h1>');
});

it('leaves the column null when the repository has no readme', function () {
    $origin = makeGitRepoWith(['composer.json' => json_encode(['name' => 'acme/demo'])]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => null,
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->readme_html)->toBeNull();
});

it('keeps the previous readme and still completes when the new sync cannot read one', function () {
    $origin = makeGitRepoWith(['composer.json' => json_encode(['name' => 'acme/demo'])]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => '<h1>Alt</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    // The sync itself must succeed, and a missing README must not blank what was stored.
    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBe('<h1>Alt</h1>');
});

it('keeps the previous readme and still completes when the new readme fails to render', function () {
    // ReadmeLocator::find() never throws — a missing/directory/symlinked README all come
    // back as null from inside it, so none of those exercise SyncPackage's own try/catch.
    // The only realistic way to reach that catch is ReadmeRenderer::render() throwing on a
    // markdown parse failure, which it does deliberately on invalid encoding (see its
    // docblock). \xFF is not a valid UTF-8 byte in any position, so this is a byte value,
    // not a locale- or filesystem-encoding-dependent construct: file_put_contents() writes
    // it verbatim, and git stores blob content exactly as given (no line endings appear
    // here for core.autocrlf to touch) — reproducible on any machine that can run this
    // suite at all.
    $origin = makeGitRepoWith([
        'composer.json' => json_encode(['name' => 'acme/demo']),
        'README.md' => "# Hi\n\xFF invalid utf-8 byte\n",
    ]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => '<h1>Alt</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    // The sync itself must succeed, and an unparsable README must not blank what was
    // stored — this is the one case that actually reaches syncReadme()'s try/catch.
    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBe('<h1>Alt</h1>');
});
