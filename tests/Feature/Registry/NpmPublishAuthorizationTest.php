<?php

// tests/Feature/Registry/NpmPublishAuthorizationTest.php
// Absicherung gegen Supply-Chain-Injection über den npm-Publish-Pfad (Finding C1):
// Ein org-fremder Publish-Token darf NICHT in eine öffentliche Registry publishen,
// nur weil die Registry oeffentlich lesbar ist. publishBody() ist globaler Helfer.
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Storage;

it('blocks a foreign-org publish token from publishing to a public registry', function () {
    Storage::fake('artifacts');

    // Org A: oeffentliche Registry mit einem Paket.
    $orgA = Organization::factory()->create();
    $group = Group::factory()->for($orgA)->create(['slug' => 'orga-public', 'public' => true]);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Org B: eigener, org-weiter Publish-Token (group_id = null).
    $orgB = Organization::factory()->create();
    [, $evil] = RegistryToken::issue($orgB, 'evil', null, TokenAbility::Publish);

    $this->withHeaders(['Authorization' => 'Bearer '.$evil])
        ->putJson('/r/orga-public/leftpad', publishBody('leftpad', '9.9.9', 'leftpad-9.9.9.tgz', 'evil-bytes'))
        ->assertForbidden();

    // Kein Seiteneffekt: weder Version noch Tarball noch dist-tag-Ueberschreibung.
    expect($pkg->fresh()->versions()->count())->toBe(0)
        ->and($pkg->fresh()->dist_tags['latest'] ?? null)->not->toBe('9.9.9');
    Storage::disk('artifacts')->assertMissing("tarballs/{$pkg->id}/leftpad-9.9.9.tgz");
});

it('still allows a legitimate same-org publish token to publish to the public registry', function () {
    Storage::fake('artifacts');

    $orgA = Organization::factory()->create();
    $group = Group::factory()->for($orgA)->create(['slug' => 'orga-public', 'public' => true]);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Token gehoert zur Ziel-Group (bzw. deren Org): publishHeaderFor() gibt einen
    // Publish-Token fuer group->organization mit group_id = group aus.
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
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    // Anonymes GET auf das packument der oeffentlichen Registry bleibt erlaubt.
    $this->getJson('/r/orga-public/leftpad')->assertOk();
});
