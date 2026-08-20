<?php

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Npm\NpmMetadataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a packument with dist-tags and tarball urls scoped to the group', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit', 'dist_tags' => ['latest' => '1.2.0']]);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.2.0', 'version_pretty' => '1.2.0',
        'metadata' => ['name' => '@noixdev/ui-kit', 'version' => '1.2.0', 'dependencies' => []],
        'dist_shasum' => str_repeat('a', 40),
        'dist_integrity' => 'sha512-abc',
        'dist_tarball_name' => 'ui-kit-1.2.0.tgz',
    ]);

    $doc = app(NpmMetadataBuilder::class)->build($pkg, 'https://registry.test/r/kadenz');

    expect($doc['name'])->toBe('@noixdev/ui-kit')
        ->and($doc['dist-tags'])->toBe(['latest' => '1.2.0'])
        ->and($doc['versions']['1.2.0']['dist']['tarball'])
        ->toBe('https://registry.test/r/kadenz/@noixdev/ui-kit/-/ui-kit-1.2.0.tgz')
        ->and($doc['versions']['1.2.0']['dist']['shasum'])->toBe(str_repeat('a', 40))
        ->and($doc['versions']['1.2.0']['dist']['integrity'])->toBe('sha512-abc')
        ->and($doc['versions']['1.2.0']['name'])->toBe('@noixdev/ui-kit');
});

it('derives latest from highest semver when dist_tags is empty', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'thing', 'dist_tags' => null]);
    foreach (['1.0.0', '2.1.0', '2.0.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create(['version' => $v, 'version_pretty' => $v, 'metadata' => ['name' => 'thing', 'version' => $v], 'dist_tarball_name' => "thing-$v.tgz"]);
    }
    $doc = app(NpmMetadataBuilder::class)->build($pkg, 'https://registry.test/r/kadenz');
    expect($doc['dist-tags']['latest'])->toBe('2.1.0');
});

it('overrides malicious name/version/dist from stored metadata', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'safe', 'dist_tags' => ['latest' => '1.0.0']]);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0', 'version_pretty' => '1.0.0',
        'metadata' => ['name' => 'evil', 'version' => '9.9.9', 'dist' => ['tarball' => 'https://evil.test/x.tgz']],
        'dist_tarball_name' => 'safe-1.0.0.tgz',
    ]);
    $doc = app(NpmMetadataBuilder::class)->build($pkg, 'https://registry.test/r/kadenz/');
    expect($doc['versions']['1.0.0']['name'])->toBe('safe')
        ->and($doc['versions']['1.0.0']['version'])->toBe('1.0.0')
        ->and($doc['versions']['1.0.0']['dist']['tarball'])->toBe('https://registry.test/r/kadenz/safe/-/safe-1.0.0.tgz');
});

it('omits deprecated entirely for a live package', function () {
    $package = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'thing']);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0']);

    $doc = app(NpmMetadataBuilder::class)->build($package, 'https://reg.test');

    expect($doc['versions']['1.0.0'])->not->toHaveKey('deprecated');
});

it('puts the composed sentence on every version', function () {
    $package = Package::factory()->create([
        'type' => PackageType::Npm,
        'name' => 'thing',
        'abandoned_at' => now(),
        'replacement_package' => '@scope/nachfolger',
        'abandonment_reason' => 'Wird nicht mehr gepflegt.',
    ]);
    foreach (['1.0.0', '1.1.0'] as $v) {
        PackageVersion::factory()->for($package)->create(['version' => $v]);
    }

    $doc = app(NpmMetadataBuilder::class)->build($package, 'https://reg.test');

    foreach (['1.0.0', '1.1.0'] as $v) {
        expect($doc['versions'][$v]['deprecated'])
            ->toBe('Wird nicht mehr gepflegt. Bitte stattdessen @scope/nachfolger verwenden.');
    }
});

it('uses the default sentence when only the switch is set', function () {
    $package = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'thing', 'abandoned_at' => now()]);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0']);

    $doc = app(NpmMetadataBuilder::class)->build($package, 'https://reg.test');

    expect($doc['versions']['1.0.0']['deprecated'])->toBe('Dieses Paket wird nicht mehr gepflegt.');
});

it('does not let a published manifest forge its own deprecation', function () {
    $package = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'thing']);
    PackageVersion::factory()->for($package)->create([
        'version' => '1.0.0',
        'metadata' => ['deprecated' => 'gefälschte Warnung'],
    ]);

    $doc = app(NpmMetadataBuilder::class)->build($package, 'https://reg.test');

    // The registry decides this field, not the uploaded package.json.
    expect($doc['versions']['1.0.0'])->not->toHaveKey('deprecated');
});
