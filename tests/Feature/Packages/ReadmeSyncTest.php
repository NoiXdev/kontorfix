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
