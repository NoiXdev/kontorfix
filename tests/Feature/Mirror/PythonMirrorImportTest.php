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
 * @return array<string, mixed>
 */
function pep691File(string $filename, string $url, ?string $sha256 = null, ?string $requiresPython = null, ?string $uploadTime = null): array
{
    return array_filter([
        'filename' => $filename,
        'url' => $url,
        'hashes' => $sha256 !== null ? ['sha256' => $sha256] : [],
        'requires-python' => $requiresPython,
        'upload-time' => $uploadTime,
    ], fn ($v) => $v !== null && $v !== []);
}

function mirroredPythonPackage(Group $group, MirrorSource $source, string $name): Package
{
    return Package::factory()->inOrgOf($group)->create([
        'organization_id' => $source->organization_id,
        'name' => $name,
        'type' => PackageType::Python,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => $name,
        'repository_url' => null,
    ]);
}

it('imports both an sdist and a wheel from a PEP 691 project detail feed', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    $sdistBytes = 'sdist-bytes';
    $wheelBytes = 'wheel-bytes';
    $sdistSha256 = hash('sha256', $sdistBytes);
    $wheelSha256 = hash('sha256', $wheelBytes);

    Http::fake([
        '*/simple/pkg/' => Http::response([
            'meta' => ['api-version' => '1.0'],
            'name' => 'pkg',
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz', $sdistSha256, '>=3.9', '2026-01-01T00:00:00Z'),
                pep691File('pkg-1.0.0-py3-none-any.whl', 'https://repo.test/dist/pkg-1.0.0-py3-none-any.whl', $wheelSha256, '>=3.9', '2026-01-02T00:00:00Z'),
            ],
        ], 200, ['Content-Type' => 'application/vnd.pypi.simple.v1+json']),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response($sdistBytes, 200),
        '*/dist/pkg-1.0.0-py3-none-any.whl' => Http::response($wheelBytes, 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->pythonDists()->count())->toBe(2);

    $sdist = $pkg->pythonDists()->where('filename', 'pkg-1.0.0.tar.gz')->first();
    $wheel = $pkg->pythonDists()->where('filename', 'pkg-1.0.0-py3-none-any.whl')->first();

    expect($sdist)->not->toBeNull()
        ->and($sdist->filetype)->toBe('sdist')
        ->and($sdist->version)->toBe('1.0.0')
        ->and($sdist->path)->toBe('pypi/'.$pkg->id.'/pkg-1.0.0.tar.gz')
        ->and($sdist->sha256)->toBe($sdistSha256)
        ->and($sdist->size)->toBe(strlen($sdistBytes))
        ->and($sdist->requires_python)->toBe('>=3.9')
        ->and($sdist->uploaded_at->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');

    expect($wheel)->not->toBeNull()
        ->and($wheel->filetype)->toBe('bdist_wheel')
        ->and($wheel->version)->toBe('1.0.0')
        ->and($wheel->path)->toBe('pypi/'.$pkg->id.'/pkg-1.0.0-py3-none-any.whl')
        ->and($wheel->sha256)->toBe($wheelSha256)
        ->and($wheel->size)->toBe(strlen($wheelBytes));

    Storage::disk('artifacts')->assertExists($sdist->path);
    Storage::disk('artifacts')->assertExists($wheel->path);
});

it('defaults uploaded_at to now() when upload-time is absent', function () {
    Storage::fake('artifacts');
    Carbon\Carbon::setTestNow('2026-03-15T12:00:00Z');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response('bytes', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    $dist = $pkg->pythonDists()->where('filename', 'pkg-1.0.0.tar.gz')->first();
    expect($dist)->not->toBeNull()
        ->and($dist->uploaded_at->toIso8601String())->toBe('2026-03-15T12:00:00+00:00');

    Carbon\Carbon::setTestNow();
});

it('requests the PEP 503 normalized name with the PEP 691 JSON Accept header', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'Foo.Bar_baz');

    Http::fake([
        '*/simple/foo-bar-baz/' => Http::response(['files' => []], 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    // hasHeader() is a subset check — it would still pass if the PEP 691 value merely got
    // appended alongside acceptJson()'s default rather than replacing it (i.e. the request
    // actually going out as `Accept: application/json, application/vnd.pypi.simple.v1+json`).
    // Reading the header's own value list and asserting it equals exactly one entry is what
    // catches that: PyPI's simple API is picky about a client asking for exactly its vendor
    // media type, not that type merely being present among others.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/simple/foo-bar-baz/')
        && $r->header('Accept') === ['application/vnd.pypi.simple.v1+json']);
});

it('throws MirrorSyncFailed naming PEP 691 when the response is not valid JSON', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake([
        '*/simple/pkg/' => Http::response('<html>not json</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())->toContain('PEP 691');
    });

    expect($pkg->pythonDists()->count())->toBe(0);
});

it('throws MirrorSyncFailed when the mirror has no PEP 691 metadata for the project', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'missing');

    Http::fake(['*/simple/missing/' => Http::response('', 404)]);

    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())->toContain('nicht gefunden')->toContain('PEP 691');
    });

    expect($pkg->pythonDists()->count())->toBe(0);
});

it('keeps the metadata-fetch failure message entirely German, never splicing in UpstreamException\'s English text', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake(['*/simple/pkg/' => Http::response('boom', 500)]);

    $thrown = fn () => app(MirrorImporter::class)->import($pkg, $source);
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (MirrorSyncFailed $e) {
        expect($e->getMessage())
            ->toContain('HTTP 500')
            ->not->toContain('Upstream')
            ->not->toContain('returned');
    });
});

it('fails the whole import on a sha256 mismatch and persists nothing for that file', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz', str_repeat('0', 64)),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response('actual-bytes', 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->pythonDists()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('imports a file without a declared hash unverified, persisting the computed sha256', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    $bytes = 'unverified-bytes';
    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response($bytes, 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    $dist = $pkg->pythonDists()->where('filename', 'pkg-1.0.0.tar.gz')->first();
    expect($dist)->not->toBeNull()
        ->and($dist->sha256)->toBe(hash('sha256', $bytes));
});

it('refuses an oversize distribution and creates neither artifact nor row for it', function () {
    Storage::fake('artifacts');
    config(['kontorfix.python_max_dist_bytes' => 16]);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    $oversized = str_repeat('x', 64);
    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response($oversized, 200),
    ]);

    expect(fn () => app(MirrorImporter::class)->import($pkg, $source))->toThrow(MirrorSyncFailed::class);

    expect($pkg->pythonDists()->count())->toBe(0);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('does not re-download a file whose local row and artifact already match (idempotent re-sync)', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    $bytes = 'stable-bytes';
    $sha256 = hash('sha256', $bytes);

    $fixture = fn () => Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz', $sha256),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response($bytes, 200),
    ]);

    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->pythonDists()->count())->toBe(1);

    // Second sync: same feed, same artifact — nothing should be re-fetched from /dist/.
    $fixture();
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->pythonDists()->count())->toBe(1);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/dist/'));
});

it('never deletes a local distribution that the upstream feed no longer lists', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
                pep691File('pkg-1.0.0-py3-none-any.whl', 'https://repo.test/dist/pkg-1.0.0-py3-none-any.whl'),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response('sdist-bytes', 200),
        '*/dist/pkg-1.0.0-py3-none-any.whl' => Http::response('wheel-bytes', 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);
    expect($pkg->pythonDists()->count())->toBe(2);

    // Upstream only lists the sdist now — the wheel must survive locally.
    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
            ],
        ], 200),
    ]);
    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->pythonDists()->count())->toBe(2)
        ->and($pkg->pythonDists()->where('filename', 'pkg-1.0.0-py3-none-any.whl')->exists())->toBeTrue();
});

it('skips a malformed file entry silently and still imports the others', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirroredPythonPackage($group, $source, 'pkg');

    Http::fake([
        '*/simple/pkg/' => Http::response([
            'files' => [
                pep691File('pkg-1.0.0.tar.gz', 'https://repo.test/dist/pkg-1.0.0.tar.gz'),
                ['filename' => '../../etc/passwd', 'url' => 'https://repo.test/dist/evil'],
                ['filename' => 'not-a-real-extension.exe', 'url' => 'https://repo.test/dist/exe'],
                'not-even-an-array',
                pep691File('pkg-1.1.0.tar.gz', 'https://repo.test/dist/pkg-1.1.0.tar.gz'),
            ],
        ], 200),
        '*/dist/pkg-1.0.0.tar.gz' => Http::response('bytes-1', 200),
        '*/dist/pkg-1.1.0.tar.gz' => Http::response('bytes-2', 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    expect($pkg->pythonDists()->count())->toBe(2);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/dist/evil') || str_contains($r->url(), '/dist/exe'));
});
