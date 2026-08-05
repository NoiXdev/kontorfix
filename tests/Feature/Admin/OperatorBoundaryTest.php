<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    // Admin of the operator org is grandfathered into the global super-admin role.
    $this->superAdmin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('denies a customer-org admin the super-admin-only surfaces', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    foreach (['/admin/organizations', '/admin/users', '/admin/oidc', '/admin/storage', '/admin/system', '/admin/webhooks', '/admin/status'] as $url) {
        $this->actingAs($custAdmin)->get($url)->assertForbidden();
    }
});

it('lets a customer-org admin reach their own scoped registry surface', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    $this->actingAs($custAdmin)->get('/admin/packages')->assertOk();
    $this->actingAs($custAdmin)->get('/admin/groups')->assertOk();
    $this->actingAs($custAdmin)->get('/admin/tokens')->assertOk();
});

it('denies a plain member the console entirely', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($member)->get('/admin/packages')->assertForbidden();
    $this->actingAs($member)->get('/admin/groups')->assertForbidden();
});

it('allows the super-admin through the instance-wide surfaces', function () {
    $this->actingAs($this->superAdmin)->get('/admin/organizations')->assertOk();
    $this->actingAs($this->superAdmin)->get('/admin/users')->assertOk();
});

it('now allows creating an admin or maintainer in a customer org', function () {
    $cust = Organization::factory()->create(['is_operator' => false]);

    $this->actingAs($this->superAdmin)->post('/admin/users', [
        'name' => 'X', 'email' => 'x@x.test', 'organization_id' => $cust->id, 'role' => 'admin', 'password' => 'geheim-1234',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->superAdmin)->post('/admin/users', [
        'name' => 'Y', 'email' => 'y@x.test', 'organization_id' => $cust->id, 'role' => 'maintainer', 'password' => 'geheim-1234',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'x@x.test')->first()->role)->toBe(UserRole::Admin)
        ->and(User::where('email', 'y@x.test')->first()->role)->toBe(UserRole::Maintainer);
});
