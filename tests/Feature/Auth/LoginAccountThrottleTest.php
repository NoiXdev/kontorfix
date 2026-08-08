<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;

// The login throttle keys on email+IP, so an attacker with a pool of genuinely distinct
// source addresses gets five attempts per minute per address against one account and is
// unbounded in aggregate. A second, account-scoped counter closes that, mirroring the
// user-keyed limiter the 2FA challenge already uses.
//
// The account-scoped counter refuses further *failures* only. It is keyed on the target
// account alone, so anyone who knows an address could otherwise burn it on demand — a
// counter that also refused the correct password would be an anonymous, indefinitely
// repeatable lockout of that account.

it('throttles failed login attempts against one account across rotating source addresses', function () {
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

    // Yet another address, and another wrong password: now answered by the throttle
    // instead of the credential check.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    Event::assertDispatched(Lockout::class);

    expect(session('errors')->first('email'))->not->toBe(trans('auth.failed'));
});

it('still admits the account holder with the correct password while the account counter is burned', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // The counter is well past its limit, yet the person who actually holds the password
    // gets in — otherwise anyone who knows the address owns a lockout button.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('does not fold an accented lookalike address onto a real account counter', function () {
    $user = User::factory()->create(['email' => 'tim@x.com']);

    // Twenty failures against an address that is not an account at all. Transliterating
    // the key would fold "ï" to "i" and burn tim@x.com's counter.
    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => 'tïm@x.com', 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'also-wrong'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
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
