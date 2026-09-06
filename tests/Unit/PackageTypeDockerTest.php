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
