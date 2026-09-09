<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Exceptions\MirrorSyncFailed;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Mirror\MirrorImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{version: string, dist: array<string, mixed>, description?: string}
 */
function npmMirrorVersion(string $version, string $tarballUrl, ?string $shasum = null, ?string $integrity = null): array
{
    return [
        'name' => 'ignored-by-importer',
        'version' => $version,
        'dist' => array_filter([
            'tarball' => $tarballUrl,
            'shasum' => $shasum,
            'integrity' => $integrity,
        ], fn ($v) => $v !== null),
    ];
}

function mirroredNpmPackage(Group $group, MirrorSource $source, string $name): Package
{
    return Package::factory()->inOrgOf($group)->create([
        'organization_id' => $source->organization_id,
        'name' => $name,
        'type' => PackageType::Npm,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => $name,
        'repository_url' => null,
    ]);
}

it('imports both versions of a scoped npm packument, tarballs, integrity and dist-tags all', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, '@vendor/pkg');
    $pkg->update(['dist_tags' => ['beta' => '0.9.0']]);

    $bytesV1 = 'tarball-bytes-v1';
    $bytesV2 = 'tarball-bytes-v2';
    $shasum1 = sha1($bytesV1);
    $integrity1 = 'sha512-'.base64_encode(hash('sha512', $bytesV1, true));
    $shasum2 = sha1($bytesV2);
    $integrity2 = 'sha512-'.base64_encode(hash('sha512', $bytesV2, true));

    Http::fake([
        '*/@vendor%2Fpkg' => Http::response([
            'name' => '@vendor/pkg',
            'dist-tags' => ['latest' => '1.1.0'],
            'time' => ['1.0.0' => '2024-01-01T00:00:00.000Z', '1.1.0' => '2024-02-01T00:00:00.000Z'],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/pkg-1.0.0.tgz', $shasum1, $integrity1),
                '1.1.0' => npmMirrorVersion('1.1.0', 'https://repo.test/pkg-1.1.0.tgz', $shasum2, $integrity2),
            ],
        ], 200),
        '*/pkg-1.0.0.tgz' => Http::response($bytesV1, 200),
        '*/pkg-1.1.0.tgz' => Http::response($bytesV2, 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2);

    $v1 = $pkg->versions()->where('version', '1.0.0')->first();
    $v2 = $pkg->versions()->where('version', '1.1.0')->first();

    expect($v1)->not->toBeNull()
        ->and($v1->version_pretty)->toBe('1.0.0')
        ->and($v1->metadata['version'])->toBe('1.0.0')
        ->and($v1->released_at->toIso8601String())->toBe('2024-01-01T00:00:00+00:00')
        ->and($v1->dist_path)->toBe('tarballs/'.$pkg->id.'/pkg-1.0.0.tgz')
        ->and($v1->dist_tarball_name)->toBe('pkg-1.0.0.tgz')
        ->and($v1->dist_size)->toBe(strlen($bytesV1))
        ->and($v1->dist_shasum)->toBe($shasum1)
        ->and($v1->dist_integrity)->toBe($integrity1);

    expect($v2)->not->toBeNull()
        ->and($v2->dist_path)->toBe('tarballs/'.$pkg->id.'/pkg-1.1.0.tgz')
        ->and($v2->dist_size)->toBe(strlen($bytesV2))
        ->and($v2->dist_shasum)->toBe($shasum2)
        ->and($v2->dist_integrity)->toBe($integrity2);

    Storage::disk('artifacts')->assertExists($v1->dist_path);
    Storage::disk('artifacts')->assertExists($v2->dist_path);

    // dist-tags merged onto the package the way NpmPublishService::publish() does it:
    // the feed's "latest" is added, the pre-existing "beta" tag (not present in the feed)
    // survives untouched.
    expect($pkg->fresh()->dist_tags)->toBe(['beta' => '0.9.0', 'latest' => '1.1.0']);
});

it('throws MirrorSyncFailed and persists nothing when the tarball bytes do not match dist.shasum', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz', sha1('expected-bytes')),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('actual-bytes-differ', 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->versions()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('throws MirrorSyncFailed and persists nothing when the tarball bytes do not match dist.integrity', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    $wrongIntegrity = 'sha512-'.base64_encode(hash('sha512', 'expected-bytes', true));

    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz', null, $wrongIntegrity),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('actual-bytes-differ', 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->versions()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('does not destroy a previously verified artifact when a re-sync integrity check fails with no shasum to guard the write', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    $goodBytes = 'previously-verified-good-bytes';
    $goodIntegrity = 'sha512-'.base64_encode(hash('sha512', $goodBytes, true));
    $distPath = 'tarballs/'.$pkg->id.'/acme-demo-1.0.0.tgz';
    Storage::disk('artifacts')->put($distPath, $goodBytes);
    $pkg->versions()->create([
        'version' => '1.0.0',
        'version_pretty' => '1.0.0',
        'source_reference' => null,
        'metadata' => [],
        'dist_path' => $distPath,
        'dist_size' => strlen($goodBytes),
        'dist_integrity' => $goodIntegrity,
    ]);

    // Upstream now declares a *different* integrity for the same version — forcing a
    // re-download — but, without a shasum to gate fetchArtifact's own atomic move, actually
    // serves bytes that don't even match its own newly-declared integrity (a corrupt or
    // malicious response). The write must land somewhere other than $distPath, so this
    // failure cannot cost the artifact a previous, successful sync already verified.
    $claimedIntegrity = 'sha512-'.base64_encode(hash('sha512', 'whatever-the-feed-claims', true));
    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz', null, $claimedIntegrity),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('corrupted-bytes', 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    Storage::disk('artifacts')->assertExists($distPath);
    expect(Storage::disk('artifacts')->get($distPath))->toBe($goodBytes);
    expect(collect(Storage::disk('artifacts')->allFiles())->filter(fn ($f) => $f !== $distPath))->toBeEmpty();
    expect($pkg->versions()->where('version', '1.0.0')->first()->dist_integrity)->toBe($goodIntegrity);
});

it('does not re-download a tarball whose local row and file already match (idempotent re-sync)', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    $bytes = 'stable-tarball-bytes';
    $shasum = sha1($bytes);

    $fixture = fn () => Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz', $shasum),
                '1.1.0' => npmMirrorVersion('1.1.0', 'https://repo.test/acme-demo-1.1.0.tgz', $shasum),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response($bytes, 200),
        '*/acme-demo-1.1.0.tgz' => Http::response($bytes, 200),
    ]);

    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->versions()->count())->toBe(2);

    // Second sync: same feed, same artifacts — nothing should be re-fetched from the tarball URLs.
    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '.tgz'));
});

it('never deletes a local version that the upstream feed no longer lists', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz'),
                '1.1.0' => npmMirrorVersion('1.1.0', 'https://repo.test/acme-demo-1.1.0.tgz'),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('bytes-1', 200),
        '*/acme-demo-1.1.0.tgz' => Http::response('bytes-2', 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->versions()->count())->toBe(2);

    // Upstream only lists 1.0.0 now — 1.1.0 must survive locally.
    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz'),
            ],
        ], 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(2)
        ->and($pkg->versions()->where('version', '1.1.0')->exists())->toBeTrue();
});

it('throws MirrorSyncFailed with a German "nicht gefunden" message when the mirror has no packument for the package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-missing');

    Http::fake(['*/acme-missing' => Http::response('', 404)]);

    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())->toContain('nicht gefunden');
    });

    expect($pkg->versions()->count())->toBe(0);
});

it('keeps the packument-fetch failure message entirely German, never splicing in UpstreamException\'s English text', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    Http::fake(['*/acme-demo' => Http::response('boom', 500)]);

    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())
            ->toContain('HTTP 500')
            ->not->toContain('Upstream')
            ->not->toContain('returned');
    });
});

it('refuses an oversize tarball and creates neither artifact nor row for it', function () {
    Storage::fake('artifacts');
    config(['kontorfix.npm_max_tarball_bytes' => 16]);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    $oversized = str_repeat('x', 64);
    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz'),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response($oversized, 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->versions()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('renders the packument-level readme into readme_html', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');

    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'readme' => "# Hello\n\nWorld.",
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz'),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('bytes', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->fresh()->readme_html)
        ->toContain('<h1>Hello</h1>')
        ->toContain('<p>World.</p>');
});

it('never fails the import when the packument readme is absent, and leaves readme_html unchanged', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Npm]);
    $pkg = mirroredNpmPackage($group, $source, 'acme-demo');
    $pkg->update(['readme_html' => '<p>previous</p>']);

    Http::fake([
        '*/acme-demo' => Http::response([
            'name' => 'acme-demo',
            'dist-tags' => [],
            'versions' => [
                '1.0.0' => npmMirrorVersion('1.0.0', 'https://repo.test/acme-demo-1.0.0.tgz'),
            ],
        ], 200),
        '*/acme-demo-1.0.0.tgz' => Http::response('bytes', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->versions()->count())->toBe(1)
        ->and($pkg->fresh()->readme_html)->toBe('<p>previous</p>');
});
