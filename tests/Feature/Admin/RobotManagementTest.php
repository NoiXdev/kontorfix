<?php

use App\Enums\AccountType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an operator admin create a robot and issue a key', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $this->actingAs($admin)->post('/admin/robots', [
        'name' => 'CI', 'organization_id' => $op->id, 'role' => 'maintainer',
    ])->assertRedirect();

    // Robots are service accounts and carry no email.
    $robot = User::firstWhere('name', 'CI');
    expect($robot->account_type)->toBe(AccountType::Robot);
    expect($robot->email)->toBeNull();

    $this->actingAs($admin)->post("/admin/robots/{$robot->id}/keys", ['name' => 'k', 'permission' => 'write'])
        ->assertRedirect()->assertSessionHas('plainApiKey');
});

it('denies non-operator members', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $customer->id, 'role' => 'member']);
    $this->actingAs($member)->get('/admin/robots')->assertForbidden();
});
