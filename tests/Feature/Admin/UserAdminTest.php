<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('creates a user with an org, role and hashed, verified credentials', function () {
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Neu', 'email' => 'neu@kunde.test', 'organization_id' => $cust->id,
        'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $u = User::where('email', 'neu@kunde.test')->first();
    expect($u)->not->toBeNull()
        ->and($u->organization_id)->toBe($cust->id)
        ->and($u->role)->toBe(UserRole::Member)
        ->and($u->email_verified_at)->not->toBeNull()
        ->and(Hash::check('geheim-1234', $u->password))->toBeTrue();
});

it('forbids maintainers from managing users', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/users')->assertForbidden();
    $this->actingAs($m)->post('/admin/users', ['name' => 'x', 'email' => 'x@x.test', 'organization_id' => $this->operator->id, 'role' => 'admin', 'password' => 'geheim-1234'])->assertForbidden();
});

it('can change a users role', function () {
    $u = User::factory()->for($this->operator)->create(['role' => UserRole::Member]);
    $this->actingAs($this->admin)->put("/admin/users/{$u->id}", ['role' => 'maintainer'])->assertRedirect();
    expect($u->fresh()->role)->toBe(UserRole::Maintainer);
});

it('refuses to delete yourself', function () {
    $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertSessionHasErrors();
    expect(User::find($this->admin->id))->not->toBeNull();
});

it('refuses to delete the last operator admin', function () {
    // Allowed case: with two operator admins, one can be deleted.
    $secondOperatorAdmin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
    $this->actingAs($this->admin)->delete("/admin/users/{$secondOperatorAdmin->id}")->assertRedirect();
    expect(User::find($secondOperatorAdmin->id))->toBeNull();

    // Blocked case: now $this->admin is the last operator admin. An admin from another
    // organization bypasses the self-deletion rule, but the count rule protects the last operator admin.
    $foreignAdmin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $this->actingAs($foreignAdmin)->delete("/admin/users/{$this->admin->id}")->assertSessionHasErrors();
    expect(User::find($this->admin->id))->not->toBeNull();
});

it('deletes a regular user', function () {
    $u = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($this->admin)->delete("/admin/users/{$u->id}")->assertRedirect();
    expect(User::find($u->id))->toBeNull();
});
