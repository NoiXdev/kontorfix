<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A super-admin-equivalent caller: admin of the operator organization. Mirrors
 * PackageApiTest's operatorWriteToken() (kept file-local — a shared top-level helper of
 * the same name across two files loaded in one process would fatal on redeclaration).
 */
function abandonmentWriteToken(): string
{
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    return $plain;
}

it('marks a package as abandoned and returns the three fields on the resource', function () {
    $plain = abandonmentWriteToken();
    $package = Package::factory()->create(['type' => 'composer']);

    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
        'replacement_package' => 'acme/successor',
        'abandonment_reason' => 'Nicht mehr gepflegt.',
    ])
        ->assertOk()
        ->assertJsonPath('data.replacement_package', 'acme/successor')
        ->assertJsonPath('data.abandonment_reason', 'Nicht mehr gepflegt.')
        ->assertJsonPath('data.abandoned_at', fn ($value) => $value !== null);

    $package->refresh();
    expect($package->abandoned_at)->not->toBeNull()
        ->and($package->replacement_package)->toBe('acme/successor')
        ->and($package->abandonment_reason)->toBe('Nicht mehr gepflegt.');
});

it('does not reset the abandonment timestamp when re-marking an already-abandoned package', function () {
    $plain = abandonmentWriteToken();
    $package = Package::factory()->create(['abandoned_at' => now()->subDays(3)]);
    $original = $package->abandoned_at;

    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
        'abandonment_reason' => 'Neuer Grund.',
    ])->assertOk();

    expect($package->fresh()->abandoned_at->toIso8601String())->toBe($original->toIso8601String())
        ->and($package->fresh()->abandonment_reason)->toBe('Neuer Grund.');
});

it('clears the replacement and reason when unmarking', function () {
    $plain = abandonmentWriteToken();
    $package = Package::factory()->create([
        'abandoned_at' => now(),
        'replacement_package' => 'symfony/mailer',
        'abandonment_reason' => 'Alt.',
    ]);

    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => false,
        'replacement_package' => 'symfony/mailer',
        'abandonment_reason' => 'Alt.',
    ])->assertOk()
        ->assertJsonPath('data.abandoned_at', null)
        ->assertJsonPath('data.replacement_package', null)
        ->assertJsonPath('data.abandonment_reason', null);

    $package->refresh();
    expect($package->abandoned_at)->toBeNull()
        ->and($package->replacement_package)->toBeNull()
        ->and($package->abandonment_reason)->toBeNull();
});

it('applies the same per-type replacement name validation as the admin console', function () {
    $plain = abandonmentWriteToken();
    $package = Package::factory()->create(['type' => 'composer', 'name' => 'acme/demo']);

    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
        'replacement_package' => 'kein-vendor-prefix',
    ])->assertStatus(422)->assertJsonValidationErrors('replacement_package');

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('refuses a read-scoped API key from writing an abandonment', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($admin, 'r', ApiKeyPermission::Read);
    $package = Package::factory()->create();

    // Blocked by AuthenticateApiKey itself (a read key may only GET/HEAD/OPTIONS), before
    // the route's own scope check ever runs — this is the same gate that store()/resync()/
    // destroy() rely on for every other package write.
    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
    ])->assertStatus(403);

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('forbids a member (write key, no admin/maintainer role) from marking a package abandoned', function () {
    $org = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    [, $plain] = ApiKey::issue($member, 'w', ApiKeyPermission::Write);
    $package = Package::factory()->create();

    // A member's key passes AuthenticateApiKey (permission allows writes) but is stopped
    // by the `operator` route middleware, which gates on canAdministerConsole().
    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
    ])->assertForbidden();

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('forbids a write key belonging to another organization from marking the package abandoned', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($adminA, 'w', ApiKeyPermission::Write);

    $groupB = Group::factory()->for($orgB)->create();
    $package = Package::factory()->inOrgOf($groupB)->create();
    $package->groups()->attach($groupB);

    // adminA administers orgA but not orgB, and the package is attached only to a
    // registry in orgB — assertCanWritePackage() must reject this even though the key is
    // a write key and its owner clears the `operator` middleware.
    $this->withToken($plain)->putJson("/api/v1/packages/{$package->id}/abandonment", [
        'abandoned' => true,
    ])->assertForbidden();

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('exposes abandoned_at, replacement_package and abandonment_reason on the show resource', function () {
    $plain = abandonmentWriteToken();
    $package = Package::factory()->create([
        'abandoned_at' => now(),
        'replacement_package' => 'acme/successor',
        'abandonment_reason' => 'Alt.',
    ]);

    $this->withToken($plain)->getJson("/api/v1/packages/{$package->id}")
        ->assertOk()
        ->assertJsonPath('data.replacement_package', 'acme/successor')
        ->assertJsonPath('data.abandonment_reason', 'Alt.')
        ->assertJsonPath('data.abandoned_at', fn ($value) => $value !== null);
});
