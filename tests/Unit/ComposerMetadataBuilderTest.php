<?php

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Composer\ComposerMetadataBuilder;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds minified composer v2 metadata with dist urls scoped to the group', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0.0',
        'version_pretty' => 'v1.0.0',
        'metadata' => ['name' => 'acme/demo', 'require' => ['php' => '>=8.2']],
    ]);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $doc = app(ComposerMetadataBuilder::class)->build($pkg, $group, 'https://registry.test/r/kadenz');
    $versions = MetadataMinifier::expand($doc['packages']['acme/demo']);

    expect($versions[0]['version'])->toBe('v1.0.0')
        ->and($versions[0]['dist']['url'])->toBe('https://registry.test/r/kadenz/dists/acme/demo/1.0.0.0.zip')
        ->and($versions[0]['dist']['type'])->toBe('zip')
        ->and($versions[0]['require']['php'])->toBe('>=8.2');
});

it('includes multiple versions ordered newest first and merges source reference', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'https://git.test/acme/demo.git']);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'source_reference' => str_repeat('a', 40),
        'metadata' => ['name' => 'acme/demo'], 'released_at' => now()->subDay(),
    ]);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.1.0.0', 'version_pretty' => 'v1.1.0', 'source_reference' => str_repeat('b', 40),
        'metadata' => ['name' => 'acme/demo'], 'released_at' => now(),
    ]);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $doc = app(ComposerMetadataBuilder::class)->build($pkg, $group, 'https://registry.test/r/kadenz');
    $versions = MetadataMinifier::expand($doc['packages']['acme/demo']);

    expect($versions)->toHaveCount(2)
        ->and($versions[0]['version'])->toBe('v1.1.0')          // newest first
        ->and($versions[0]['dist']['reference'])->toBe(str_repeat('b', 40))
        ->and($versions[0]['source']['url'])->toBe('https://git.test/acme/demo.git')
        ->and($versions[0]['source']['reference'])->toBe(str_repeat('b', 40));
});

it('omits the source block when the package has no repository url', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => null]);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'metadata' => ['name' => 'acme/demo']]);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $doc = app(ComposerMetadataBuilder::class)->build($pkg, $group, 'https://registry.test/r/kadenz');
    $versions = MetadataMinifier::expand($doc['packages']['acme/demo']);

    expect($versions[0])->not->toHaveKey('source');
});

it('overrides malicious dist, source and version keys from the stored composer.json', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'https://git.test/acme/demo.git']);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0.0',
        'version_pretty' => 'v1.0.0',
        'source_reference' => str_repeat('c', 40),
        'metadata' => [
            'name' => 'evil/pwn',
            'version' => '99.9.9',
            'version_normalized' => '99.9.9.0',
            'dist' => ['type' => 'zip', 'url' => 'https://evil.test/malware.zip', 'reference' => 'x'],
            'source' => ['type' => 'git', 'url' => 'https://evil.test/x.git', 'reference' => 'x'],
        ],
    ]);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $doc = app(ComposerMetadataBuilder::class)->build($pkg, $group, 'https://registry.test/r/kadenz/');
    $v = MetadataMinifier::expand($doc['packages']['acme/demo'])[0];

    expect($v['version'])->toBe('v1.0.0')
        ->and($v['version_normalized'])->toBe('1.0.0.0')
        ->and($v['dist']['url'])->toBe('https://registry.test/r/kadenz/dists/acme/demo/1.0.0.0.zip')
        ->and($v['source']['url'])->toBe('https://git.test/acme/demo.git');
});

it('omits abandoned entirely for a live package whose manifest never declared it', function () {
    $package = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);

    $doc = app(ComposerMetadataBuilder::class)->build($package, Group::factory()->create(['slug' => 'kadenz']), 'https://reg.test');

    $entries = $doc['packages'][$package->name];

    foreach ($entries as $entry) {
        expect($entry)->not->toHaveKey('abandoned');
    }
});

it('strips an abandoned key forged by the tag composer.json for a live package', function () {
    // The tag's raw composer.json is merged into the version entry (Packagist parity),
    // so a repository under someone else's control could declare itself abandoned — and
    // steer installers at an attacker-chosen replacement — with the operator never having
    // touched the abandonment switch, or after having un-marked it. The registry, not the
    // mirrored manifest, must own this field.
    $package = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($package)->create([
        'version' => '1.0.0.0',
        'version_pretty' => 'v1.0.0',
        'metadata' => ['abandoned' => 'evil/pkg'],
    ]);

    $doc = app(ComposerMetadataBuilder::class)->build($package, Group::factory()->create(['slug' => 'kadenz']), 'https://reg.test');

    $entries = $doc['packages'][$package->name];

    foreach ($entries as $entry) {
        expect($entry)->not->toHaveKey('abandoned');
    }
});

it('carries the replacement onto every version after the deltas are expanded', function () {
    $package = Package::factory()->create([
        'name' => 'acme/demo',
        'abandoned_at' => now(),
        'replacement_package' => 'symfony/mailer',
    ]);
    foreach (['1.0.0.0' => 'v1.0.0', '1.1.0.0' => 'v1.1.0', '2.0.0.0' => 'v2.0.0'] as $norm => $pretty) {
        PackageVersion::factory()->for($package)->create(['version' => $norm, 'version_pretty' => $pretty]);
    }

    $doc = app(ComposerMetadataBuilder::class)->build($package, Group::factory()->create(['slug' => 'kadenz']), 'https://reg.test');

    // Expand exactly the way Composer's MetadataMinifier does, so this asserts what a
    // client sees rather than what we happened to build before minification.
    $expanded = MetadataMinifier::expand($doc['packages'][$package->name]);

    expect($expanded)->toHaveCount(3);
    foreach ($expanded as $entry) {
        expect($entry['abandoned'])->toBe('symfony/mailer');
    }
});

it('emits a bare true when no replacement is named', function () {
    $package = Package::factory()->create(['name' => 'acme/demo', 'abandoned_at' => now()]);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);

    $doc = app(ComposerMetadataBuilder::class)->build($package, Group::factory()->create(['slug' => 'kadenz']), 'https://reg.test');
    $expanded = MetadataMinifier::expand($doc['packages'][$package->name]);

    expect($expanded[0]['abandoned'])->toBe(true);
});
