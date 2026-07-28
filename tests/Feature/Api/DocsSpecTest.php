<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the openapi document to an operator admin and lists api paths', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $res = $this->actingAs($admin)->get('/docs/api.json')->assertOk();

    expect($res->json('paths'))->toHaveKey('/api/v1/me');
});

it('denies non-operators access to the api docs', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $customer->id, 'role' => 'member']);

    $this->actingAs($member)->get('/docs/api.json')->assertForbidden();
});
