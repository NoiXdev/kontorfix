<?php

// tests/Feature/Registry/NpmTarballTest.php
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('streams a stored npm tarball with the right content type', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/leftpad-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/leftpad/-/leftpad-1.0.0.tgz')
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('streams a scoped npm tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'ui-kit-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/ui-kit-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'scoped-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/@noixdev/ui-kit/-/ui-kit-1.0.0.tgz')->assertOk();
});

it('answers 404, not the tarball, when the assignment is revoked between resolve and bounds check', function () {
    // npm's tarball-side instance of the race pinned end-to-end in ComposerFlowTest for
    // ComposerController::dist(): VersionEntitlement::boundsFor() must fail closed when
    // AssignmentWriter::revoke() (no transaction) detaches the pivot row in the window
    // between findAccessible() resolving the package and respondTarball()'s own separate
    // boundsFor() call. Same DB::beforeExecuting() hook, same predicate-shape match — see
    // that test's docblock for the drift scenario that would break the match.
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/leftpad-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $detached = false;
    DB::beforeExecuting(function (string $query) use (&$detached, $group, $pkg) {
        if (! $detached
            && str_contains($query, 'group_package')
            && ! str_contains($query, 'available_until" is null or')
        ) {
            $detached = true;
            $group->packages()->detach($pkg->getKey());
        }
    });

    $this->withHeaders($headers)->get(registryPath($group).'/leftpad/-/leftpad-1.0.0.tgz')->assertNotFound();

    expect($detached)->toBeTrue()
        ->and($group->packages()->whereKey($pkg->id)->exists())->toBeFalse();
});

it('denies tarball download without package access', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => 'x']);
    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/leftpad/-/leftpad-1.0.0.tgz')->assertNotFound();
});
