<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Setup\SetupToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prints and persists a fresh setup token while the instance is not set up', function () {
    $this->artisan('setup:token')
        ->expectsOutputToContain('FIRST-RUN SETUP TOKEN')
        ->assertSuccessful();

    // The token was regenerated and is now the active one.
    expect(app(SetupToken::class)->current())->not->toBeNull();
});

it('clears the token and does nothing once the instance is set up', function () {
    // A user existing means the wizard is sealed.
    User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create();
    app(SetupToken::class)->regenerate();

    $this->artisan('setup:token')
        ->expectsOutputToContain('already set up')
        ->assertSuccessful();

    expect(app(SetupToken::class)->current())->toBeNull();
});
