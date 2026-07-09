<?php

use App\Enums\PackageType;
use App\Services\Package\PackageDependencies;

it('extracts composer require and require-dev', function () {
    $meta = ['require' => ['php' => '^8.2', 'monolog/monolog' => '^3.0'], 'require-dev' => ['pestphp/pest' => '^2.0']];
    $deps = app(PackageDependencies::class)->for(PackageType::Composer, $meta);

    expect($deps['runtime'])->toBe(['php' => '^8.2', 'monolog/monolog' => '^3.0']);
    expect($deps['dev'])->toBe(['pestphp/pest' => '^2.0']);
});

it('extracts npm dependencies and devDependencies', function () {
    $meta = ['dependencies' => ['left-pad' => '^1.0.0'], 'devDependencies' => ['jest' => '^29']];
    $deps = app(PackageDependencies::class)->for(PackageType::Npm, $meta);

    expect($deps['runtime'])->toBe(['left-pad' => '^1.0.0']);
    expect($deps['dev'])->toBe(['jest' => '^29']);
});

it('returns empty arrays for missing keys', function () {
    $deps = app(PackageDependencies::class)->for(PackageType::Composer, []);
    expect($deps)->toBe(['runtime' => [], 'dev' => []]);
});
