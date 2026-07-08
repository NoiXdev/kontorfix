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
