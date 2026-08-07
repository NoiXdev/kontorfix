<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;

// The login throttle keys on email+IP, so an attacker with a pool of genuinely distinct
// source addresses gets five attempts per minute per address against one account and is
// unbounded in aggregate. A second, account-scoped counter closes that, mirroring the
// user-keyed limiter the 2FA challenge already uses.

it('throttles login attempts against one account across rotating source addresses', function () {
    $user = User::factory()->create();

    // Twenty failures, each from its own address — the per-IP counter (5/minute) is never
    // reached, so only an account-scoped counter can stop this.
    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }

    Event::fake([Lockout::class]);

    // Yet another address, and this time the *correct* password: still refused.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    Event::assertDispatched(Lockout::class);
});

it('does not let a flooded account lock out a different account', function () {
    $victim = User::factory()->create();
    $bystander = User::factory()->create();

    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $victim->email, 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->post('/login', ['email' => $bystander->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($bystander);
});

it('still lets a user in after a handful of typos from their own address', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $ignored) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'typo']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('clears the account counter on a successful login', function () {
    $user = User::factory()->create();

    foreach (range(1, 19) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    // Counter reset: the next failure is not immediately at the limit.
    $this->post('/logout');
    $this->flushSession();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});
