<?php

// tests/Feature/Registry/NpmTarballTest.php
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Support\Facades\Storage;

it('streams a stored npm tarball with the right content type', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/leftpad-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('streams a scoped npm tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'ui-kit-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/ui-kit-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'scoped-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/@noixdev/ui-kit/-/ui-kit-1.0.0.tgz')->assertOk();
});

it('denies tarball download without package access', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => 'x']);
    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')->assertNotFound();
});
