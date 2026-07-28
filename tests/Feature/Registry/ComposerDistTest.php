<?php

use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('builds the zip lazily, stores it on the artifacts disk and streams it', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');

    $sha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    $res->assertOk()->assertHeader('content-type', 'application/zip');
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$sha}.zip");
});

it('serves the cached zip on the second request without rebuilding', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    // Second request: dist_path is set, no rebuild needed
    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    $sha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->dist_path)
        ->toBe("dists/{$pkg->id}/{$sha}.zip");
});

it('rebuilds the dist when a tag was force-pushed to a new commit', function () {
    Storage::fake('artifacts');
    $fixture = FixtureRepo::make();
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.$fixture]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    $oldSha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$oldSha}.zip");

    // Real force-push: same tag, new commit.
    $git = fn (string $cmd) => Process::path($fixture)->run($cmd)->throw();
    $git('git -c user.email=t@t -c user.name=t commit --allow-empty -m forcepush');
    $git('git tag -f v1.0.0');
    (new SyncPackage($pkg))->handle();

    $newSha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    expect($newSha)->not->toBe($oldSha);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    // New SHA path is built and served — no stale delivery.
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$newSha}.zip");
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->dist_path)
        ->toBe("dists/{$pkg->id}/{$newSha}.zip");
});

it('denies dist download without access', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    // Package NOT assigned
    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertNotFound();
});

it('returns 404 for an unknown version of an assigned package', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/9.9.9.0.zip')->assertNotFound();
});
