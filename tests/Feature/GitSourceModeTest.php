<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('mirrors npm versions from git tags and builds tarballs with integrity', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create([
        'type' => PackageType::Npm,
        'source_mode' => PackageSourceMode::Git,
        'name' => '@acme/widget',
        'repository_url' => 'file://'.FixtureRepo::makeNpm(),
    ]);

    (new SyncPackage($pkg))->handle();

    $pkg->refresh();
    expect($pkg->sync_status)->toBe(SyncStatus::Synced)
        ->and($pkg->versions()->pluck('version')->all())->toContain('1.0.0', '1.1.0');

    $v = $pkg->versions()->where('version', '1.1.0')->first();
    expect($v->dist_tarball_name)->toBe('widget-1.1.0.tgz')
        ->and($v->dist_shasum)->toMatch('/^[0-9a-f]{40}$/')
        ->and($v->dist_integrity)->toStartWith('sha512-')
        ->and($v->dist_path)->not->toBeNull();
    Storage::disk('artifacts')->assertExists($v->dist_path);
});

it('mirrors python versions from git tags and builds sdists with sha256', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create([
        'type' => PackageType::Python,
        'source_mode' => PackageSourceMode::Git,
        'name' => 'acme-lib',
        'repository_url' => 'file://'.FixtureRepo::makePython(),
    ]);

    (new SyncPackage($pkg))->handle();

    $pkg->refresh();
    expect($pkg->sync_status)->toBe(SyncStatus::Synced);

    $dist = $pkg->pythonDists()->where('version', '1.1.0')->first();
    expect($dist)->not->toBeNull()
        ->and($dist->filename)->toBe('acme_lib-1.1.0.tar.gz')
        ->and($dist->filetype)->toBe('sdist')
        ->and($dist->sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($dist->source_reference)->toMatch('/^[0-9a-f]{40}$/');
    Storage::disk('artifacts')->assertExists($dist->path);
});

it('is idempotent: re-syncing a git-mirror npm package does not duplicate versions', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create([
        'type' => PackageType::Npm, 'source_mode' => PackageSourceMode::Git,
        'name' => '@acme/widget', 'repository_url' => 'file://'.FixtureRepo::makeNpm(),
    ]);

    (new SyncPackage($pkg))->handle();
    (new SyncPackage($pkg))->handle();

    expect($pkg->versions()->count())->toBe(2)
        ->and($pkg->pythonDists()->count())->toBe(0);
});

it('serves a git-mirror npm packument with the built tarball metadata', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create([
        'type' => PackageType::Npm, 'source_mode' => PackageSourceMode::Git,
        'name' => '@acme/widget', 'repository_url' => 'file://'.FixtureRepo::makeNpm(),
    ]);
    $group->packages()->attach($pkg->id);
    (new SyncPackage($pkg))->handle();

    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/@acme/widget')->assertOk()->json();
    expect($doc['name'])->toBe('@acme/widget')
        ->and($doc['versions']['1.1.0']['dist']['tarball'])->toContain('/@acme/widget/-/widget-1.1.0.tgz')
        ->and($doc['versions']['1.1.0']['dist']['integrity'])->toStartWith('sha512-');

    // The tarball advertised in the packument is streamable.
    $path = parse_url($doc['versions']['1.1.0']['dist']['tarball'], PHP_URL_PATH);
    $this->withHeaders(tokenHeaderFor($group))->get($path)->assertOk();
});

it('serves git-mirror python dists over the simple index and streams the sdist', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create([
        'type' => PackageType::Python, 'source_mode' => PackageSourceMode::Git,
        'name' => 'acme-lib', 'repository_url' => 'file://'.FixtureRepo::makePython(),
    ]);
    $group->packages()->attach($pkg->id);
    (new SyncPackage($pkg))->handle();

    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/simple/acme-lib/')
        ->assertOk()
        ->assertSee('acme_lib-1.1.0.tar.gz');

    $this->withHeaders(tokenHeaderFor($group))->get("/r/kadenz/pypi/files/{$pkg->id}/acme_lib-1.1.0.tar.gz")
        ->assertOk();
});

it('rejects publishing to a git-mirror npm package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create([
        'type' => PackageType::Npm, 'source_mode' => PackageSourceMode::Git,
        'name' => 'leftpad', 'repository_url' => 'file:///tmp/x',
    ]);
    $group->packages()->attach($pkg->id);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '9.9.9', 'leftpad-9.9.9.tgz', 'x'))
        ->assertStatus(409);
});
