<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an authenticated robot session on any web route', function () {
    // Simulates an already-established robot login (e.g. via the passkey vendor path).
    $org = Organization::factory()->create();
    $robot = User::factory()->robot()->create(['organization_id' => $org->id]);

    $this->actingAs($robot)->get('/dashboard')
        ->assertForbidden();

    $this->assertGuest();
});

it('lets a normal human session through', function () {
    $org = Organization::factory()->create();
    $human = User::factory()->create(['organization_id' => $org->id]);

    // /dashboard requires verified; make sure the human doesn't fail on the robot guard.
    $response = $this->actingAs($human)->get('/dashboard');
    expect($response->status())->not->toBe(403);
});
