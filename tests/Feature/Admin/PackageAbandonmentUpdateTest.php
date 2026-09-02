<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/**
 * A super-admin-equivalent operator: Admin of the operator organization. Named
 * differently from PythonPackageCreateTest's file-local `operatorAdmin()` — both files
 * load in the same test process, and a same-named top-level function would fatal on
 * redeclaration.
 */
function abandonmentOperator(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('marks a package as abandoned', function () {
    $package = Package::factory()->create();

    $this->actingAs(abandonmentOperator())
        ->put(route('admin.packages.abandonment', $package), [
            'abandoned' => true,
            'replacement_package' => 'acme/successor',
            'abandonment_reason' => 'Nicht mehr gepflegt.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $package->refresh();
    expect($package->abandoned_at)->not->toBeNull()
        ->and($package->replacement_package)->toBe('acme/successor')
        ->and($package->abandonment_reason)->toBe('Nicht mehr gepflegt.');
});

it('rejects a replacement name that is not valid for the package type', function () {
    $package = Package::factory()->create(['name' => 'acme/demo']);

    $this->actingAs(abandonmentOperator())
        ->put(route('admin.packages.abandonment', $package), [
            'abandoned' => true,
            'replacement_package' => 'kein-vendor-prefix',
        ])
        ->assertSessionHasErrors('replacement_package');

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('clears the replacement and reason when the switch goes off', function () {
    $package = Package::factory()->create([
        'abandoned_at' => now(),
        'replacement_package' => 'symfony/mailer',
        'abandonment_reason' => 'Alt.',
    ]);

    $this->actingAs(abandonmentOperator())
        ->put(route('admin.packages.abandonment', $package), [
            'abandoned' => false,
            // Stale values a client could still be holding when toggling off — the server
            // must actively null them rather than merely default because they're absent.
            'replacement_package' => 'symfony/mailer',
            'abandonment_reason' => 'Alt.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $package->refresh();
    expect($package->abandoned_at)->toBeNull()
        ->and($package->replacement_package)->toBeNull()
        ->and($package->abandonment_reason)->toBeNull();
});

it('keeps the original timestamp when the package is already abandoned', function () {
    $package = Package::factory()->create(['abandoned_at' => now()->subDays(3)]);
    $original = $package->abandoned_at;

    $this->actingAs(abandonmentOperator())
        ->put(route('admin.packages.abandonment', $package), [
            'abandoned' => true,
            'abandonment_reason' => 'Neuer Grund.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($package->fresh()->abandoned_at->toIso8601String())->toBe($original->toIso8601String())
        ->and($package->fresh()->abandonment_reason)->toBe('Neuer Grund.');
});

it('forbids a member from marking a package abandoned', function () {
    $package = Package::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->put(route('admin.packages.abandonment', $package), ['abandoned' => true])
        ->assertForbidden();

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('forbids marking a package outside the administered org', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    $groupB = Group::factory()->for($orgB)->create();
    $package = Package::factory()->inOrgOf($groupB)->create();
    $package->groups()->attach($groupB);

    $this->actingAs($adminA)
        ->put(route('admin.packages.abandonment', $package), ['abandoned' => true])
        ->assertForbidden();

    expect($package->fresh()->abandoned_at)->toBeNull();
});

it('caps the abandonment reason length', function () {
    $package = Package::factory()->create();

    $this->actingAs(abandonmentOperator())
        ->put(route('admin.packages.abandonment', $package), [
            'abandoned' => true,
            'abandonment_reason' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors('abandonment_reason');

    expect($package->fresh()->abandoned_at)->toBeNull();
});
