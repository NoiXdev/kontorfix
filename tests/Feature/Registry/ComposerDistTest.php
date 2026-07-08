<?php

use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('builds the zip lazily, stores it on the artifacts disk and streams it', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');

    $res->assertOk()->assertHeader('content-type', 'application/zip');
    Storage::disk('artifacts')->assertExists('dists/'.$pkg->id.'/1.0.0.0.zip');
});

it('serves the cached zip on the second request without rebuilding', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    // Zweiter Request: dist_path ist gesetzt, kein Rebuild nötig
    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    expect($pkg->versions()->where('version', '1.0.0.0')->first()->dist_path)
        ->toBe('dists/'.$pkg->id.'/1.0.0.0.zip');
});

it('denies dist download without access', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    // Paket NICHT zugewiesen
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
