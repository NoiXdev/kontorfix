<?php

// tests/Feature/Registry/NpmPublishAuthorizationTest.php
// Protection against supply-chain injection via the npm publish path (Finding C1):
// A publish token from a foreign org must NOT be able to publish to a public registry
// just because the registry is publicly readable. publishBody() is a global helper.
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Storage;

it('blocks a foreign-org publish token from publishing to a public registry', function () {
    Storage::fake('artifacts');

    // Org A: public registry with one package.
    $orgA = Organization::factory()->create();
    $group = Group::factory()->for($orgA)->create(['slug' => 'orga-public', 'public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Org B: its own org-wide publish token (group_id = null).
    $orgB = Organization::factory()->create();
    [, $evil] = RegistryToken::issue($orgB, 'evil', null, TokenAbility::Publish);

    $this->withHeaders(['Authorization' => 'Bearer '.$evil])
        ->putJson('/r/orga-public/leftpad', publishBody('leftpad', '9.9.9', 'leftpad-9.9.9.tgz', 'evil-bytes'))
        ->assertForbidden();

    // No side effect: no version, no tarball, no dist-tag override.
    expect($pkg->fresh()->versions()->count())->toBe(0)
        ->and($pkg->fresh()->dist_tags['latest'] ?? null)->not->toBe('9.9.9');
    Storage::disk('artifacts')->assertMissing("tarballs/{$pkg->id}/leftpad-9.9.9.tgz");
});

it('still allows a legitimate same-org publish token to publish to the public registry', function () {
    Storage::fake('artifacts');

    $orgA = Organization::factory()->create();
    $group = Group::factory()->for($orgA)->create(['slug' => 'orga-public', 'public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Token belongs to the target group (or rather its org): publishHeaderFor() issues
    // a publish token for group->organization with group_id = group.
    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/orga-public/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'ok-bytes'))
        ->assertOk();

    expect($pkg->fresh()->versions()->where('version', '1.0.0')->exists())->toBeTrue()
        ->and($pkg->fresh()->dist_tags['latest'])->toBe('1.0.0');
});

it('still allows anonymous read of the public registry (read short-circuit unchanged)', function () {
    Storage::fake('artifacts');

    $orgA = Organization::factory()->create();
    $group = Group::factory()->for($orgA)->create(['slug' => 'orga-public', 'public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Anonymous GET on the packument of the public registry remains allowed.
    $this->getJson('/r/orga-public/leftpad')->assertOk();
});
