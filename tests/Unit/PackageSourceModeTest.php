<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;

it('allows only git for composer', function () {
    expect(PackageSourceMode::allowedFor(PackageType::Composer))->toBe([PackageSourceMode::Git]);
});

it('allows only publish for npm', function () {
    expect(PackageSourceMode::allowedFor(PackageType::Npm))->toBe([PackageSourceMode::Publish]);
});

it('allows both for python', function () {
    expect(PackageSourceMode::allowedFor(PackageType::Python))
        ->toBe([PackageSourceMode::Publish, PackageSourceMode::Git]);
});

it('defaults to the only allowed mode where there is one', function () {
    expect(PackageSourceMode::defaultFor(PackageType::Composer))->toBe(PackageSourceMode::Git)
        ->and(PackageSourceMode::defaultFor(PackageType::Npm))->toBe(PackageSourceMode::Publish);
});

it('defaults python to publish', function () {
    expect(PackageSourceMode::defaultFor(PackageType::Python))->toBe(PackageSourceMode::Publish);
});

it('reports every type in the enum, so a new type cannot be forgotten', function () {
    foreach (PackageType::cases() as $type) {
        // The ->not->toBeEmpty() assertion is decorative: an exhaustive match with no
        // catch-all arm throws UnhandledMatchError for an unforgotten type before this
        // line is ever reached. It's the call itself, not the assertion, that is the canary.
        expect(PackageSourceMode::allowedFor($type))->not->toBeEmpty();
    }
});
