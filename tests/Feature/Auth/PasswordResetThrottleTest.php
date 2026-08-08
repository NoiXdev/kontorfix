<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

// Both password-reset endpoints are unauthenticated and expensive: Laravel wraps the token
// lookup in a 200ms Timebox, so an unthrottled endpoint is a cheap way to pin every PHP
// worker. They also send mail, so an unthrottled endpoint mail-bombs any known address.

beforeEach(fn () => Notification::fake());

it('throttles the reset-link endpoint per source address', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
});

it('throttles the reset-link endpoint per target account across rotating source addresses', function () {
    $user = User::factory()->create();

    // Ten requests spread over ten distinct source addresses — under the per-IP limit
    // every time, so only the account-scoped counter can stop this.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->post('/forgot-password', ['email' => $user->email])
            ->assertStatus(302);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->post('/forgot-password', ['email' => $user->email])
        ->assertStatus(429);
});

it('throttles the reset-password endpoint per source address', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        $this->post('/reset-password', [
            'token' => 'guessed-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(302);
    }

    $this->post('/reset-password', [
        'token' => 'guessed-token',
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(429);
});

it('still lets the token holder complete a reset after their send bucket is flooded', function () {
    $victim = User::factory()->create();
    $token = Password::createToken($victim);

    // The attacker exhausts the per-account *send* bucket (10/hour) from rotating
    // addresses. Completing a reset is a different action: the requester has to present
    // a 64-character token, so there is nothing per-account to brute force there, and
    // sharing the bucket would let anyone who knows an address block the victim for the
    // whole 60-minute token lifetime.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->post('/forgot-password', ['email' => $victim->email]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->post('/reset-password', [
            'token' => $token,
            'email' => $victim->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect(Hash::check('a-brand-new-password', $victim->refresh()->password))->toBeTrue();
});

it('does not let one account starve another out of the reset endpoint', function () {
    $victim = User::factory()->create();
    $bystander = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->post('/forgot-password', ['email' => $victim->email]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->post('/forgot-password', ['email' => $bystander->email])
        ->assertStatus(302);
});
