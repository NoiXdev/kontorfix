<?php

use App\Enums\PackageType;
use App\Services\Vcs\RepositoryProbe;
use Tests\Support\FixtureRepo;

/**
 * Exercises the real RepositoryProbe against local git fixtures (no Process fake), so the
 * actual ls-remote/clone/manifest-read chain is covered end to end — this is what catches
 * regressions like the "HEAD" positional that once suppressed all tags.
 */
it('discovers name, description, default branch and version tags for a composer repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file://'.FixtureRepo::make('acme/demo'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('acme/demo')
        ->and($result['description'])->toBe('Demo package v2')
        ->and($result['default_branch'])->toBe('main')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reads the name from a package.json for an npm repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Npm, 'file://'.FixtureRepo::makeNpm('@acme/widget'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('@acme/widget')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reads the name from a pyproject.toml for a python repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Python, 'file://'.FixtureRepo::makePython('acme-lib'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('acme-lib')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reports an unreachable repository without throwing', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file:///does/not/exist-'.uniqid());

    expect($result['ok'])->toBeFalse()
        ->and($result['versions'])->toBe([]);
});
