<?php

// tests/Feature/Registry/NpmPublishTest.php
// publishBody() and publishHeaderFor() are global helpers in tests/Pest.php.
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Storage;

it('publishes an npm version, stores the tarball and computes integrity', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $bytes = 'fake-tarball-bytes';

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', $bytes))
        ->assertOk();

    $v = $pkg->fresh()->versions()->where('version', '1.0.0')->firstOrFail();
    expect($v->dist_shasum)->toBe(sha1($bytes))
        ->and($v->dist_integrity)->toStartWith('sha512-')
        ->and($v->dist_integrity)->toBe('sha512-'.base64_encode(hash('sha512', $bytes, true)))
        ->and($pkg->fresh()->dist_tags['latest'])->toBe('1.0.0');
    Storage::disk('artifacts')->assertExists("tarballs/{$pkg->id}/leftpad-1.0.0.tgz");
});

it('publishes a scoped package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/@noixdev/ui-kit', publishBody('@noixdev/ui-kit', '2.0.0', 'ui-kit-2.0.0.tgz', 'x'))
        ->assertOk();
    expect($pkg->fresh()->versions()->where('version', '2.0.0')->exists())->toBeTrue();
});

it('rejects publish without a publish-ability token', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    [, $plain] = RegistryToken::issue($group->organization, 'read', $group, TokenAbility::Read);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))
        ->assertForbidden();

    // No side effect: neither the version nor the tarball should have been created.
    expect($pkg->fresh()->versions()->count())->toBe(0);
    Storage::disk('artifacts')->assertMissing("tarballs/{$pkg->id}/leftpad-1.0.0.tgz");
});

it('rejects republishing an existing version with 409', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $body = publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x');

    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertOk();
    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertStatus(409);
});

it('derives a safe tarball name and ignores a malicious attachment key', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // npm sends an attachment key like "@scope/name-version.tgz", or here a
    // malicious key — the storage name is derived server-side, the key is ignored.
    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', '../../evil.tgz', 'bytes'))
        ->assertOk();

    $v = $pkg->fresh()->versions()->where('version', '1.0.0')->firstOrFail();
    expect($v->dist_tarball_name)->toBe('leftpad-1.0.0.tgz');
    Storage::disk('artifacts')->assertExists("tarballs/{$pkg->id}/leftpad-1.0.0.tgz");
    // Nothing outside the per-package folder.
    foreach (Storage::disk('artifacts')->allFiles() as $f) {
        expect($f)->toStartWith("tarballs/{$pkg->id}/");
    }
});

it('derives an unscoped tarball name for a scoped package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $group->packages()->attach($pkg);

    // npm sends the real attachment key "@noixdev/ui-kit-1.0.0.tgz" (with @ and /).
    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/@noixdev/ui-kit', publishBody('@noixdev/ui-kit', '1.0.0', '@noixdev/ui-kit-1.0.0.tgz', 'bytes'))
        ->assertOk();

    expect($pkg->fresh()->versions()->where('version', '1.0.0')->first()->dist_tarball_name)->toBe('ui-kit-1.0.0.tgz');
    Storage::disk('artifacts')->assertExists("tarballs/{$pkg->id}/ui-kit-1.0.0.tgz");
});

it('rejects a non-semver version string', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', 'not-a-version', 'leftpad-x.tgz', 'x'))
        ->assertStatus(422);
});

it('rejects an empty attachment', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', ''))
        ->assertStatus(422);
});

it('rejects a tarball larger than the configured limit', function () {
    config(['kontorfix.npm_max_tarball_bytes' => 8]);
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', str_repeat('A', 64)))
        ->assertStatus(422);
    expect($pkg->fresh()->versions()->count())->toBe(0);
});

it('rejects a body whose name does not match the package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('rightpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))
        ->assertStatus(422);
});

it('rejects a package name that would derive an unsafe tarball filename', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    // Injectable via the factory (bypasses creation validation) — the publish service
    // must still check the derived filename against the regex.
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@x/..']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/@x/..', publishBody('@x/..', '1.0.0', 'whatever.tgz', 'bytes'))
        ->assertStatus(422);
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});
