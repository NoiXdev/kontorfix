<?php

// tests/Feature/Registry/NpmPublishTest.php
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Storage;

function publishBody(string $name, string $version, string $file, string $bytes): array
{
    return [
        'name' => $name,
        'versions' => [$version => ['name' => $name, 'version' => $version, 'dependencies' => []]],
        'dist-tags' => ['latest' => $version],
        '_attachments' => [$file => ['content_type' => 'application/octet-stream', 'data' => base64_encode($bytes), 'length' => strlen($bytes)]],
    ];
}

function publishHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'ci', $group, TokenAbility::Publish);

    return ['Authorization' => 'Bearer '.$plain];
}

it('publishes an npm version, stores the tarball and computes integrity', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
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
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/@noixdev/ui-kit', publishBody('@noixdev/ui-kit', '2.0.0', 'ui-kit-2.0.0.tgz', 'x'))
        ->assertOk();
    expect($pkg->fresh()->versions()->where('version', '2.0.0')->exists())->toBeTrue();
});

it('rejects publish without a publish-ability token', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    [, $plain] = RegistryToken::issue($group->organization, 'read', $group, TokenAbility::Read);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))
        ->assertForbidden();
});

it('rejects republishing an existing version with 409', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $body = publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x');

    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertOk();
    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertStatus(409);
});

it('rejects a tarball filename with path traversal', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', '../../evil.tgz', 'x'))
        ->assertStatus(422);
});

it('rejects a body whose name does not match the package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('rightpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))
        ->assertStatus(422);
});
