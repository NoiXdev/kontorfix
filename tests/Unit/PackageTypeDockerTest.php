<?php

use App\Enums\PackageType;

it('offers docker as a registry type', function () {
    expect(PackageType::tryFrom('docker'))->toBe(PackageType::Docker)
        ->and(PackageType::Docker->label())->toBe('Docker');
});

it('treats docker as publish-based', function () {
    expect(PackageType::Docker->isPublishBased())->toBeTrue();
});

it('has no git manifest file for docker, and says so with null', function () {
    // A Docker repository is never git-sourced, so there is no fourth answer here.
    // Returning a plausible-looking filename would be worse than returning nothing:
    // some caller would eventually read it.
    expect(PackageType::Docker->manifestFile())->toBeNull()
        ->and(PackageType::Composer->manifestFile())->toBe('composer.json')
        ->and(PackageType::Npm->manifestFile())->toBe('package.json')
        ->and(PackageType::Python->manifestFile())->toBe('pyproject.toml');
});

it('gives docker an install hint that names the registry host placeholder', function () {
    expect(PackageType::Docker->installHint('meinapp'))->toContain('docker pull')
        ->and(PackageType::Docker->installHint('meinapp'))->toContain('meinapp');
});

it('uses a real host in the docker install hint once one is known', function () {
    // The placeholder from Task 1 stands only until a caller can supply the registry's
    // actual host (RegistryUrl::host(), once its Group carries a domain) — see the
    // method's doc comment. Every other case ignores the parameter outright.
    expect(PackageType::Docker->installHint('meinapp', 'images.3b.de'))
        ->toBe('docker pull images.3b.de/meinapp')
        ->and(PackageType::Docker->installHint('meinapp', null))
        ->toBe('docker pull <registry-host>/meinapp')
        ->and(PackageType::Composer->installHint('acme/widget', 'images.3b.de'))
        ->toBe('composer require acme/widget');
});
