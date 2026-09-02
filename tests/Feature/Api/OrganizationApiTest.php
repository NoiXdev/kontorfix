<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an operator admin create and delete customer orgs', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $res = $this->withToken($plain)->postJson('/api/v1/organizations', ['name' => 'Kunde X', 'slug' => 'kunde-x'])
        ->assertCreated()->assertJsonPath('data.is_operator', false);

    $id = $res->json('data.id');
    $this->withToken($plain)->deleteJson("/api/v1/organizations/{$id}")->assertNoContent();
});

it('denies maintainers', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $maint = User::factory()->create(['organization_id' => $op->id, 'role' => 'maintainer']);
    [, $plain] = ApiKey::issue($maint, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/organizations')->assertForbidden();
});

it('refuses to delete an organization that still owns packages', function () {
    // Mirrors tests/Feature/Admin/OrganizationDeletionTest.php. Deleting the organization's
    // last registry through DELETE /api/v1/groups/{group} cascades the pivot rows, so the
    // organization is left with 0 users, 0 registries and N packages — which used to pass
    // every check here and hit the restrictOnDelete foreign key as an unhandled 500.
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $customer = Organization::factory()->create();
    Package::factory()->for($customer)->create();

    $this->withToken($plain)->deleteJson("/api/v1/organizations/{$customer->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('organization');

    expect(Organization::whereKey($customer->id)->exists())->toBeTrue();
});
