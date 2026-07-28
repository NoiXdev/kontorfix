<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an authenticated robot session on any web route', function () {
    // Simuliert einen bereits etablierten Robot-Login (z. B. via Passkey-Vendorpfad).
    $org = Organization::factory()->create();
    $robot = User::factory()->robot()->create(['organization_id' => $org->id]);

    $this->actingAs($robot)->get('/dashboard')
        ->assertForbidden();

    $this->assertGuest();
});

it('lets a normal human session through', function () {
    $org = Organization::factory()->create();
    $human = User::factory()->create(['organization_id' => $org->id]);

    // /dashboard erfordert verified; sicherstellen, dass der Mensch nicht am Robot-Guard scheitert.
    $response = $this->actingAs($human)->get('/dashboard');
    expect($response->status())->not->toBe(403);
});
