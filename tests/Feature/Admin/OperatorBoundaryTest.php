<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->operatorAdmin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('denies a customer-org admin access to operator admin routes', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    foreach (['/admin/organizations', '/admin/users', '/admin/packages', '/admin/oidc', '/admin/storage'] as $url) {
        $this->actingAs($custAdmin)->get($url)->assertForbidden();
    }
});

it('allows the operator admin through', function () {
    $this->actingAs($this->operatorAdmin)->get('/admin/organizations')->assertOk();
    $this->actingAs($this->operatorAdmin)->get('/admin/users')->assertOk();
});

it('rejects creating an admin or maintainer in a customer org', function () {
    $cust = Organization::factory()->create(['is_operator' => false]);

    $this->actingAs($this->operatorAdmin)->post('/admin/users', [
        'name' => 'X', 'email' => 'x@x.test', 'organization_id' => $cust->id, 'role' => 'admin', 'password' => 'geheim-1234',
    ])->assertSessionHasErrors('role');

    $this->actingAs($this->operatorAdmin)->post('/admin/users', [
        'name' => 'Y', 'email' => 'y@x.test', 'organization_id' => $cust->id, 'role' => 'maintainer', 'password' => 'geheim-1234',
    ])->assertSessionHasErrors('role');

    // Member in a customer org remains allowed
    $this->actingAs($this->operatorAdmin)->post('/admin/users', [
        'name' => 'Z', 'email' => 'z@x.test', 'organization_id' => $cust->id, 'role' => 'member', 'password' => 'geheim-1234',
    ])->assertSessionHasNoErrors();
});

it('refuses to demote the last operator admin via update', function () {
    // operatorAdmin is the only admin of the operator org
    $this->actingAs($this->operatorAdmin)->put("/admin/users/{$this->operatorAdmin->id}", ['role' => 'member'])
        ->assertSessionHasErrors('user');
    expect($this->operatorAdmin->fresh()->role)->toBe(UserRole::Admin);

    // With a second operator admin, the demotion is allowed
    $second = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
    $this->actingAs($this->operatorAdmin)->put("/admin/users/{$second->id}", ['role' => 'member'])->assertRedirect();
    expect($second->fresh()->role)->toBe(UserRole::Member);
});
