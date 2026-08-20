<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Upstream;
use App\Services\Upstream\ComposerProxyService;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rewrites every version dist url to the proxy route and caches the payload', function () {
    $group = Group::factory()->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    Http::fake(['*/p2/acme/demo.json' => Http::response([
        'packages' => ['acme/demo' => MetadataMinifier::minify([
            ['name' => 'acme/demo', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/a.zip', 'reference' => 'r1']],
            ['name' => 'acme/demo', 'version' => 'v1.1.0', 'version_normalized' => '1.1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/b.zip', 'reference' => 'r2']],
        ])],
    ], 200)]);

    $doc = app(ComposerProxyService::class)->metadata($group, $up, 'acme/demo', 'https://registry.test/r/kadenz');
    $versions = MetadataMinifier::expand($doc['packages']['acme/demo']);

    expect($versions)->toHaveCount(2)
        ->and($versions[0]['dist']['url'])->toStartWith('https://registry.test/r/kadenz/proxy/composer/'.$up->id.'/acme/demo/')
        ->and($up->metadataCache()->where('package_name', 'acme/demo')->exists())->toBeTrue();

    // Second call uses the cache (no further HTTP call).
    Http::fake();
    app(ComposerProxyService::class)->metadata($group, $up, 'acme/demo', 'https://registry.test/r/kadenz');
    Http::assertNothingSent();
});

it('passes an upstream abandoned marker through untouched without adding or stripping our own', function () {
    $group = Group::factory()->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    // This name is not a package held locally by the registry, so the proxy is the only
    // path that can serve it — there is no Package row and thus no abandonmentNotice() to consult.
    Http::fake(['*/p2/upstream/only.json' => Http::response([
        'packages' => ['upstream/only' => MetadataMinifier::minify([
            ['name' => 'upstream/only', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'abandoned' => 'upstream/replacement', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/a.zip', 'reference' => 'r1']],
        ])],
    ], 200)]);

    $doc = app(ComposerProxyService::class)->metadata($group, $up, 'upstream/only', 'https://registry.test/r/kadenz');
    $versions = MetadataMinifier::expand($doc['packages']['upstream/only']);

    expect($versions[0]['abandoned'])->toBe('upstream/replacement');
});
