<?php

use App\Models\User;
use App\Services\Auth\KnownClients;
use Carbon\CarbonInterval;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

// The per-request login limiter keys on (email, IP), so an attacker with a pool of source
// addresses gets five attempts per minute per address against one account and is unbounded
// in aggregate. Two further counters bound that — one keyed on the target account across
// every address, one keyed on the source address across every account.
//
// Neither of them may *refuse* anything. A counter keyed on an account is burnable by
// anyone who knows the address, so refusing on it is an anonymous, targeted, indefinitely
// renewable lockout of an account that may hold no second factor and no other way in.
// Both therefore buy time instead of denying access: a failure past the free allowance is
// held before it is answered, a correct password is answered at once and clears the
// account counter.
//
// These tests assert the delay itself — Sleep is faked, so every assertion is on a call the
// production code really made. An assertion on the error string would pass just as happily
// against a control that refuses and costs nothing, which is exactly how the previous
// version of this file certified an inert counter.

/** Discard the recorded sleeps so the next request is measured on its own. */
function forgetRecordedSleeps(): void
{
    Sleep::fake();
}

/** @return Closure(CarbonInterval): bool */
function sleptAtLeast(float $milliseconds): Closure
{
    return fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= $milliseconds;
}

/** @return Closure(CarbonInterval): bool */
function sleptLongerThan(float $milliseconds): Closure
{
    return fn (CarbonInterval $duration): bool => $duration->totalMilliseconds > $milliseconds;
}

beforeEach(function () {
    Sleep::fake();
});

it('holds back failures against one account once the free allowance is spent, however the source address rotates', function () {
    $user = User::factory()->create();

    // Ten failures, each from its own address — the per-(email, IP) counter (5/minute) is
    // never reached, so only an account-scoped control can see this at all.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    // Nothing is charged for the allowance itself: a user working down their password
    // manager, or an office sharing one egress, must not pay for the attacker.
    Sleep::assertNeverSlept();

    // The eleventh guess, from an eleventh address, is held before it is answered.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(sleptAtLeast(500), 1);

    // And the penalty grows: the twelfth costs strictly more than the eleventh.
    forgetRecordedSleeps();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    Sleep::assertSlept(sleptAtLeast(1000), 1);
});

it('caps the delay so one failing request cannot hold a web worker indefinitely', function () {
    $user = User::factory()->create();

    // Far past the allowance — the penalty must plateau, not grow without bound. An
    // unbounded hold would let an attacker park every PHP-FPM worker on /login and take
    // the instance down with the very control that is supposed to protect it.
    foreach (range(1, 40) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i % 250 + 1)])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    forgetRecordedSleeps();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(sleptLongerThan(5000), 0);
});

it('answers the account holder immediately with the correct password while the account counter is burned', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    forgetRecordedSleeps();

    // The counter is far past its allowance, yet the person who actually holds the
    // password gets in, and gets in without waiting. Otherwise anyone who knows the
    // address owns a lockout button — or at least a permanent slow-down button — against
    // an account that may have no second factor.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
    Sleep::assertNeverSlept();
});

it('refuses an unrecognised guess instead of answering it at full speed when the queue is full', function () {
    $user = User::factory()->create();

    foreach (range(1, 11) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // Stand in for four other failing logins already being held instance-wide — the exact
    // condition the previous release answered immediately, at full bcrypt speed, which is
    // what made four cheap connections a switch that turned the brake off for everyone.
    foreach (range(0, 3) as $slot) {
        Cache::put('login-delay-slot|'.$slot, 1, 10);
    }

    forgetRecordedSleeps();
    $before = RateLimiter::attempts('login-account|'.rawurlencode($user->email));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    // Not `auth.failed`: the guess was refused, not compared.
    expect(session('errors')->first('email'))->not->toBe(trans('auth.failed'));

    // The proof that the refusal is *pre*-comparison, which is the only kind that refuses
    // anything: a compared guess would have charged the account counter. A refusal handed
    // out after the comparison would leave the attacker with their answer.
    expect(RateLimiter::attempts('login-account|'.rawurlencode($user->email)))->toBe($before);

    // And no worker was held for it either — refusing is not a second hold.
    Sleep::assertNeverSlept();

    // Anchor: the identical request differs only in whether the queue is full. Free the
    // slots and it reaches the comparison and answers `auth.failed`, which pins the two
    // assertions above to the admission rule rather than to CSRF, the route, the `guest`
    // middleware or the per-(email, IP) counter — none of which changed between the two.
    foreach (range(0, 3) as $slot) {
        Cache::forget('login-delay-slot|'.$slot);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    expect(RateLimiter::attempts('login-account|'.rawurlencode($user->email)))->toBe($before + 1);
});

it('lets a browser this account has signed in from through a full queue', function () {
    $user = User::factory()->create();

    foreach (range(1, 11) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    foreach (range(0, 3) as $slot) {
        Cache::put('login-delay-slot|'.$slot, 1, 10);
    }

    forgetRecordedSleeps();

    // Same saturated instance, same burned counter, same fresh source address — the only
    // difference is the marker this browser picked up the last time the account signed in
    // here. Without this the bound would be an anonymous lockout with extra steps.
    $this->withKnownClient($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
    Sleep::assertNeverSlept();
});

it('marks the browser as known on a successful login', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertCookie(KnownClients::COOKIE);
});

it('marks the browser as known on a completed password reset, so a flooded account is still recoverable', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    // Bury the account past its free allowance and fill the queue: an unrecognised
    // browser cannot log in at all in this state.
    foreach (range(1, 11) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }
    foreach (range(0, 3) as $slot) {
        Cache::put('login-delay-slot|'.$slot, 1, 10);
    }

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors()->assertCookie(KnownClients::COOKIE);

    forgetRecordedSleeps();

    $this->withKnownClient($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('paces unrecognised guesses at the queue rate instead of the comparison rate', function () {
    $user = User::factory()->create();

    foreach (range(1, 11) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // Four guesses in flight. A fifth, sixth and seventh concurrent connection — the
    // shape the previous release let straight through — must now find no slot.
    foreach (range(0, 3) as $slot) {
        Cache::put('login-delay-slot|'.$slot, 1, 10);
    }

    $accountKey = 'login-account|'.rawurlencode($user->email);
    $before = RateLimiter::attempts($accountKey);

    foreach (range(100, 120) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => "guess-{$i}"]);
    }

    // Twenty-one further connections, none of them from an address the per-(email, IP)
    // counter has ever seen, and not one password was compared.
    expect(RateLimiter::attempts($accountKey))->toBe($before);
});

it('releases the delay slot it took, so the next failing request is held again', function () {
    $user = User::factory()->create();

    foreach (range(1, 15) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    forgetRecordedSleeps();

    // Five of those fifteen took a slot and must have returned it — more than the pool
    // holds. If they leaked, the pool would be full by now and every later attacker would
    // sail through undelayed.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.98'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    Sleep::assertSleptTimes(1);
});

it('holds back one source address spraying failures across many accounts', function () {
    $this->instanceAlreadySetUp();

    // One address, twenty-one different addressees, none of them an account: every
    // account-scoped counter stays at one, so only a source-scoped control sees this. The
    // per-(email, IP) counter does not either — its key changes with every email.
    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
            ->from('/login')
            ->post('/login', ['email' => "spray-{$i}@example.com", 'password' => 'wrong-password']);
    }

    forgetRecordedSleeps();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->from('/login')
        ->post('/login', ['email' => 'spray-21@example.com', 'password' => 'wrong-password']);

    Sleep::assertSlept(sleptAtLeast(500), 1);
});

it('raises Lockout once when an account crosses its free allowance', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    Event::fake([Lockout::class]);

    foreach (range(11, 13) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // One signal per burst — enough for an operator to see it, not enough to drown them.
    Event::assertDispatchedTimes(Lockout::class, 1);
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

    forgetRecordedSleeps();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'also-wrong'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    Sleep::assertNeverSlept();
});

it('does not make a different account pay for a flooded one', function () {
    $victim = User::factory()->create();
    $bystander = User::factory()->create();

    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->from('/login')
            ->post('/login', ['email' => $victim->email, 'password' => 'wrong-password']);
    }

    forgetRecordedSleeps();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->post('/login', ['email' => $bystander->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($bystander);
    Sleep::assertNeverSlept();
});

it('still lets a user in after a handful of typos from their own address', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $ignored) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'typo']);
    }

    forgetRecordedSleeps();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    Sleep::assertNeverSlept();
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

    $this->post('/logout');
    $this->flushSession();

    forgetRecordedSleeps();

    // Counter reset: the next failure is back inside the free allowance and is answered at
    // full speed, so a user who mistypes right after logging in does not keep paying for
    // an attack that has already been repelled.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
        ->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    Sleep::assertNeverSlept();
});

it('still refuses a sixth attempt from the same address against the same account', function () {
    $user = User::factory()->create();

    // The one counter that may refuse outright: its key carries the requester's own IP, so
    // burning it costs the attacker their own source address, never the holder's way in.
    foreach (range(1, 5) as $ignored) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->not->toBe(trans('auth.failed'));
});
