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
        ->and(PackageType::Docker->installHint('meinapp'))->toContain('meinapp')
        // The test's own name promises this: the caller passed no registry at all (the
        // second parameter is omitted here), so the hint must name the `<registry-host>`
        // placeholder instead of silently omitting the host segment or leaving it blank.
        // Since path addressing this is the ONLY reason the placeholder is ever reached —
        // there is no registry left that lacks an address.
        ->and(PackageType::Docker->installHint('meinapp'))->toContain('<registry-host>');
});

it('uses the registry address in the docker install hint once one is known', function () {
    // The parameter is everything an image reference writes before the repository name:
    // a bare host on a custom domain, host plus `{organisation}/{registry}` on the instance
    // host. Both come from RegistryUrl::dockerImagePrefix(), and both shapes are pinned here
    // because a hint that dropped the namespace would pull from a repository that does not
    // exist — with a 404 as the only symptom.
    expect(PackageType::Docker->installHint('meinapp', 'images.3b.de'))
        ->toBe('docker pull images.3b.de/meinapp')
        ->and(PackageType::Docker->installHint('meinapp', 'registry.3b.de/3b/intern'))
        ->toBe('docker pull registry.3b.de/3b/intern/meinapp')
        ->and(PackageType::Docker->installHint('meinapp', null))
        ->toBe('docker pull <registry-host>/meinapp')
        ->and(PackageType::Composer->installHint('acme/widget', 'images.3b.de'))
        ->toBe('composer require acme/widget');
});
