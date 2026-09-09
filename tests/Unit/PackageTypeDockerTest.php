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

/*
 * The install command is NOT tested here any more, and not because the coverage was dropped:
 * `PackageType::installHint()` no longer exists. An enum case knows its ecosystem and nothing
 * about the registry serving a package, so every command it could build was registry-less —
 * harmless-looking for Composer and npm, and for Python a `pip install <name>` that resolves
 * against PyPI instead of failing. The command is built from the registry's own address now,
 * by App\Services\Registry\SetupSnippetBuilder::installCommand(), and both Docker shapes
 * this file used to pin (bare host on a custom domain, host plus `{organisation}/{registry}`
 * on the instance host) are asserted against real registries in
 * tests/Feature/Portal/InstallCommandTest.php.
 */
