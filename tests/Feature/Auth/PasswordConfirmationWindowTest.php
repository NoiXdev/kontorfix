<?php

// The `password.confirm` window is session-global — RequirePassword reads one session key —
// so its length is the length of the ride a stolen session gets from a confirmation the
// owner made for an unrelated reason. Laravel ships three hours. These tests pin the two
// decisions taken instead: fifteen minutes for the shared window, and five for the one
// gated action that survives losing the session.

use App\Models\User;

it('expires the shared confirmation well before Laravel default three hours', function () {
    expect(config('auth.password_timeout'))->toBe(900);

    $user = User::factory()->create();

    // Confirmed 16 minutes ago: past the window, so the gated page asks again.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 960])
        ->get('/settings/tokens')
        ->assertRedirect(route('password.confirm'));

    // Anchor: 14 minutes ago is inside it, and the identical request is served. The two
    // differ in nothing but the stamp, so the redirect above is the gate rather than
    // `auth`, `verified` or the route.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 840])
        ->get('/settings/tokens')
        ->assertOk();
});

it('demands a fresher confirmation for moving the account address than for minting a token', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    // Ten minutes old: good enough for a bearer credential, which is revocable...
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 600])
        ->get('/settings/tokens')
        ->assertOk();

    // ...and not good enough to point the reset channel somewhere else, which is not.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 600])
        ->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.net'])
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->email)->toBe('owner@example.com');

    // Anchor: a four-minute-old confirmation, same request, goes through — so the redirect
    // is ConfirmPasswordOnEmailChange's shorter window and not validation or the route.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 240])
        ->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.net'])
        ->assertSessionHasNoErrors();
});
