<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Exceptions\MirrorSyncFailed;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Mirror\MirrorImporter;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{name: string, version: string, version_normalized: string, dist: array<string, mixed>, time?: string}
 */
function composerMirrorVersion(string $name, string $tag, string $normalized, string $distUrl, ?string $shasum = null, ?string $time = null): array
{
    $entry = [
        'name' => $name,
        'version' => $tag,
        'version_normalized' => $normalized,
        'dist' => array_filter([
            'type' => 'zip',
            'url' => $distUrl,
            'shasum' => $shasum,
        ], fn ($v) => $v !== null),
    ];

    if ($time !== null) {
        $entry['time'] = $time;
    }

    return $entry;
}

function mirroredComposerPackage(Group $group, MirrorSource $source, string $name): Package
{
    return Package::factory()->inOrgOf($group)->create([
        'organization_id' => $source->organization_id,
        'name' => $name,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => $name,
        'repository_url' => null,
    ]);
}

it('imports both versions of a two-version p2 feed, dist and all', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/demo');

    $shasum = sha1('zip-bytes-v1');
    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip', $shasum, '2024-01-01T00:00:00+00:00'),
                composerMirrorVersion('acme/demo', 'v1.1.0', '1.1.0.0', 'https://repo.test/dist/b.zip', null, '2024-02-01T00:00:00+00:00'),
            ])],
        ], 200),
        '*/dist/a.zip' => Http::response('zip-bytes-v1', 200),
        '*/dist/b.zip' => Http::response('zip-bytes-v2', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2);

    $v1 = $pkg->versions()->where('version', '1.0.0.0')->first();
    $v2 = $pkg->versions()->where('version', '1.1.0.0')->first();

    expect($v1)->not->toBeNull()
        ->and($v1->version_pretty)->toBe('v1.0.0')
        ->and($v1->metadata['name'])->toBe('acme/demo')
        ->and($v1->released_at->toIso8601String())->toBe('2024-01-01T00:00:00+00:00')
        ->and($v1->dist_path)->toBe('dists/'.$pkg->id.'/'.sha1('1.0.0.0').'.zip')
        ->and($v1->dist_size)->toBe(strlen('zip-bytes-v1'))
        ->and($v1->dist_shasum)->toBe($shasum);

    expect($v2)->not->toBeNull()
        ->and($v2->version_pretty)->toBe('v1.1.0')
        ->and($v2->dist_path)->toBe('dists/'.$pkg->id.'/'.sha1('1.1.0.0').'.zip')
        ->and($v2->dist_size)->toBe(strlen('zip-bytes-v2'))
        ->and($v2->dist_shasum)->toBeNull();

    Storage::disk('artifacts')->assertExists($v1->dist_path);
    Storage::disk('artifacts')->assertExists($v2->dist_path);
});

it('does not re-download an artifact whose local row and file already match (idempotent re-sync)', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/demo');

    $fixture = fn () => Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip'),
                composerMirrorVersion('acme/demo', 'v1.1.0', '1.1.0.0', 'https://repo.test/dist/b.zip'),
            ])],
        ], 200),
        '*/dist/a.zip' => Http::response('zip-bytes-v1', 200),
        '*/dist/b.zip' => Http::response('zip-bytes-v2', 200),
    ]);

    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->versions()->count())->toBe(2);

    // Second sync: same feed, same artifacts — nothing should be re-fetched from /dist/.
    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/dist/'));
});

it('never deletes a local version that the upstream feed no longer lists', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/demo');

    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip'),
                composerMirrorVersion('acme/demo', 'v1.1.0', '1.1.0.0', 'https://repo.test/dist/b.zip'),
            ])],
        ], 200),
        '*/dist/a.zip' => Http::response('zip-bytes-v1', 200),
        '*/dist/b.zip' => Http::response('zip-bytes-v2', 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->versions()->count())->toBe(2);

    // Upstream only lists 1.0.0.0 now — 1.1.0.0 must survive locally.
    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip'),
            ])],
        ], 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2)
        ->and($pkg->versions()->where('version', '1.1.0.0')->exists())->toBeTrue();
});

it('throws MirrorSyncFailed when the mirror has no Composer-v2 metadata for the package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/missing');

    Http::fake(['*/p2/acme/missing.json' => Http::response('', 404)]);

    // Pest's closure form of toThrow() only accepts a closure whose single parameter is
    // type-hinted as a concrete, instantiable class (it uses reflection on that type to
    // resolve the expected exception class, then invokes the closure with the caught
    // instance) — Throwable is an interface and is rejected by that same reflection check,
    // so the parameter has to name MirrorSyncFailed itself.
    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())->toContain('nicht gefunden')->toContain('Composer-v2');
    });

    expect($pkg->versions()->count())->toBe(0);
});

it('refuses an oversize dist and creates neither artifact nor row for it', function () {
    Storage::fake('artifacts');
    config(['kontorfix.composer_max_dist_bytes' => 16]);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/demo');

    $oversized = str_repeat('x', 64);
    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip'),
            ])],
        ], 200),
        '*/dist/a.zip' => Http::response($oversized, 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->versions()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('skips an unparseable version tag silently and still imports the others', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/demo');

    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                composerMirrorVersion('acme/demo', 'v1.0.0', '1.0.0.0', 'https://repo.test/dist/a.zip'),
                composerMirrorVersion('acme/demo', 'not-a-version-at-all', 'not-a-version-at-all', 'https://repo.test/dist/bad.zip'),
                composerMirrorVersion('acme/demo', 'v1.1.0', '1.1.0.0', 'https://repo.test/dist/b.zip'),
            ])],
        ], 200),
        '*/dist/a.zip' => Http::response('zip-bytes-v1', 200),
        '*/dist/b.zip' => Http::response('zip-bytes-v2', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/dist/bad.zip'));
});

it('streams a mirror-imported version straight from disk, without the lazy git build', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test']);
    $pkg = mirroredComposerPackage($group, $source, 'acme/mirrored');
    $group->packages()->attach($pkg);

    $path = 'dists/'.$pkg->id.'/'.sha1('1.0.0.0').'.zip';
    Storage::disk('artifacts')->put($path, 'mirror-zip-bytes');
    $pkg->versions()->create([
        'version' => '1.0.0.0',
        'version_pretty' => 'v1.0.0',
        'source_reference' => null,
        'metadata' => [],
        'dist_path' => $path,
        'dist_size' => strlen('mirror-zip-bytes'),
    ]);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/dists/acme/mirrored/1.0.0.0.zip');

    // repository_url is null on this package — a fall-through into the lazy git build
    // would abort(404) rather than serve the file, so a 200 with the exact stored bytes
    // proves the dist_path branch was taken, not the git path.
    $res->assertOk()->assertHeader('content-type', 'application/zip');
    expect($res->streamedContent())->toBe('mirror-zip-bytes');
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->download_count)->toBe(1);
});
