<?php

// `POST /confirm-password` became a security boundary the moment the credential and
// two-factor areas were put behind it. A boundary needs three things the stock
// scaffolding never had: a throttle, a trace, and a principal it cannot get wrong.

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('throttles repeated wrong passwords from one address', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    // Past the per-address limit even the correct password is refused: the key carries
    // the requester's own IP, so burning it costs the attacker their source address.
    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('leaves a trace for every failed confirmation', function () {
    Event::fake([Failed::class, Lockout::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    Event::assertDispatched(Failed::class, fn (Failed $e) => $e->user?->is($user) === true);
});

it('refuses on the account-wide counter before the comparison, so rotating source address buys nothing', function () {
    $user = User::factory()->create();

    // Burn the account-scoped counter as an attacker on other source addresses would.
    // `65be613` taught that a counter *anonymous* traffic can burn must not refuse; this
    // one is different in the only way that matters — reaching it at all needs a session
    // for the very account it protects, so the actor who can fill it is the owner or
    // somebody already inside their session.
    foreach (range(1, 21) as $i) {
        RateLimiter::hit('confirm-password-account|'.$user->id, 900);
    }

    $addressKey = 'confirm-password|'.$user->id.'|127.0.0.1';

    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    // Pre-comparison: a compared guess would have charged the per-address bucket.
    expect(RateLimiter::attempts($addressKey))->toBe(0);
    expect(session('auth.password_confirmed_at'))->toBeNull();

    // Anchor: clearing that one counter is the only change, and the identical request
    // then confirms — so the refusal above is PasswordAttemptLimiter's, not the `auth`
    // middleware's, the route's or CSRF's.
    RateLimiter::clear('confirm-password-account|'.$user->id);

    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('confirms against the session owner, not against another null-email row', function () {
    // `where('email', null)` is rewritten by Eloquent to `where "email" is null`, so
    // re-resolving the principal by a nullable identifier matches an arbitrary *other*
    // row. Two human accounts without a mailbox are enough to show it.
    $other = User::factory()->create([
        'email' => null,
        'account_type' => AccountType::Human,
        'password' => Hash::make('other-secret'),
    ]);
    $owner = User::factory()->create([
        'email' => null,
        'account_type' => AccountType::Human,
        'password' => Hash::make('owner-secret'),
    ]);
    expect($other->id)->not->toBe($owner->id);

    $this->actingAs($owner)->post('/confirm-password', ['password' => 'other-secret'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();

    $this->actingAs($owner)->post('/confirm-password', ['password' => 'owner-secret'])
        ->assertSessionHasNoErrors();
});
