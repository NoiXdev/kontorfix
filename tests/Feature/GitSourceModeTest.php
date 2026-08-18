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
