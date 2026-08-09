<?php

use App\Models\Package;
use App\Models\PackageVersion;

function runNpmGitMigration(): void
{
    $path = database_path('migrations/2026_08_09_160000_convert_npm_git_packages_to_publish.php');
    (require $path)->up();
}

it('flips an npm git package to publish and keeps everything else', function () {
    $package = Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'git',
        'repository_url' => 'https://github.test/acme/demo.git',
    ]);
    $version = PackageVersion::factory()->for($package)->create(['version' => '1.0.0']);

    runNpmGitMigration();

    $fresh = $package->fresh();
    expect($fresh->source_mode->value)->toBe('publish')
        ->and($fresh->repository_url)->toBe('https://github.test/acme/demo.git')
        ->and(PackageVersion::whereKey($version->id)->exists())->toBeTrue();
});

it('leaves a python git package alone', function () {
    $package = Package::factory()->create(['type' => 'python', 'source_mode' => 'git']);

    runNpmGitMigration();

    expect($package->fresh()->source_mode->value)->toBe('git');
});

it('leaves a composer package alone', function () {
    $package = Package::factory()->create(['type' => 'composer', 'source_mode' => 'git']);

    runNpmGitMigration();

    expect($package->fresh()->source_mode->value)->toBe('git');
});

it('leaves an npm publish package alone', function () {
    $package = Package::factory()->create(['type' => 'npm', 'source_mode' => 'publish']);

    runNpmGitMigration();

    expect($package->fresh()->source_mode->value)->toBe('publish');
});
