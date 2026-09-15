<?php

// The spec argues the one-directional guard is acceptable BECAUSE the console shows what a
// licence narrowing will do — "before saving, not after". As built it only showed it
// afterwards, and the customer page, where one edit reaches every registry of an
// organization at once, showed nothing at all. This is the missing half of that argument.
//
// It computes server-side through the very method that later decides for real
// (VersionEntitlement::applyLicence). A preview that used its own comparison could disagree
// with the outcome — and a preview nobody can trust is worse than none, because the
// operator acts on it.

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create();
    $this->package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);
});

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<JsonResponse>
 */
function previewAs(Organization $customer, array $payload): TestResponse
{
    return test()->actingAs(superAdmin())
        ->postJson(route('admin.organizations.licences.preview', $customer), $payload);
}

it('reports a registry the proposed window would narrow', function () {
    $group = Group::factory()->for($this->customer)->create(['name' => 'Produktion']);
    $group->packages()->attach($this->package->id, ['version_min' => '1.0.0', 'version_max' => '5.0.0']);

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => '1.0.0',
        'version_max' => '2.9.9',
        'available_until' => null,
    ])->assertOk()
        ->assertJsonPath('narrowed_count', 1)
        ->assertJsonPath('emptied_count', 0)
        ->assertJsonPath('registries.0.name', 'Produktion')
        ->assertJsonPath('registries.0.narrowed', true)
        ->assertJsonPath('registries.0.effective_version_max', '2.9.9');
});

it('reports a registry the proposed window would empty', function () {
    $group = Group::factory()->for($this->customer)->create(['name' => 'Alt']);
    $group->packages()->attach($this->package->id, ['version_min' => '3.0.0', 'version_max' => '5.0.0']);

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => '1.0.0',
        'version_max' => '2.9.9',
        'available_until' => null,
    ])->assertOk()
        ->assertJsonPath('emptied_count', 1)
        ->assertJsonPath('registries.0.emptied', true);
});

it('uses the real comparator, not a naive string comparison', function () {
    // The exact case that forced the client-side comparator out of the codebase: a naive
    // numeric-segment compare rates 2.0.0-beta1 equal to 2.0.0 and would report "unchanged".
    $group = Group::factory()->for($this->customer)->create();
    $group->packages()->attach($this->package->id, ['version_min' => '2.0.0-beta1', 'version_max' => '5.0.0']);

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => '2.0.0',
        'version_max' => null,
        'available_until' => null,
    ])->assertOk()
        ->assertJsonPath('registries.0.narrowed', true)
        ->assertJsonPath('registries.0.effective_version_min', '2.0.0');
});

it('reports every registry as emptied when the proposed term has already passed', function () {
    // Expiry is not absence: a lapsed licence withdraws the package everywhere, so an
    // operator backdating the date must see that before saving, not after.
    $group = Group::factory()->for($this->customer)->create();
    $group->packages()->attach($this->package->id, ['version_min' => '1.0.0']);

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => null,
        'version_max' => null,
        'available_until' => now()->subDay()->toDateString(),
    ])->assertOk()->assertJsonPath('emptied_count', 1);
});

it('says nothing is affected when no registry carries the package', function () {
    Group::factory()->for($this->customer)->create();

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => '1.0.0',
        'version_max' => null,
        'available_until' => null,
    ])->assertOk()
        ->assertJsonPath('narrowed_count', 0)
        ->assertJsonPath('emptied_count', 0)
        ->assertJsonPath('registries', []);
});

it('writes nothing', function () {
    $group = Group::factory()->for($this->customer)->create();
    $group->packages()->attach($this->package->id, ['version_min' => '1.0.0', 'version_max' => '5.0.0']);

    previewAs($this->customer, [
        'package_id' => $this->package->id,
        'version_min' => '1.0.0',
        'version_max' => '2.0.0',
        'available_until' => null,
    ])->assertOk();

    expect($this->customer->licensedPackages()->count())->toBe(0)
        ->and($group->packages()->first()->pivot->version_max)->toBe('5.0.0');
});

it('refuses a caller who does not administer the organization', function () {
    $outsider = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider)
        ->postJson(route('admin.organizations.licences.preview', $this->customer), [
            'package_id' => $this->package->id,
            'version_min' => '1.0.0',
            'version_max' => null,
            'available_until' => null,
        ])->assertForbidden();
});
