<?php

// The account holder was the one party never told that someone is working through their
// second factor — and at that point the attacker already has their password, which is the
// one fact only the owner can act on. The operator-facing half (a log line per guess, a
// burst signal, a daily ceiling) shipped with the audit fix; this is the other half.
//
// The notification rides the existing burst signal rather than inventing a second one: the
// daily counter does not roll off with the per-minute window, so the branch that fires it
// is reached at most once per account per day. That is what keeps an attacker — who can
// trigger failures at will — from turning this into a mail bomb against the victim.

use App\Models\User;
use App\Notifications\SecondFactorAttemptsDetected;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

function challengedUser(): User
{
    $user = User::factory()->create();
    $tfa = app(TwoFactorAuthenticator::class);
    $user->forceFill([
        'two_factor_secret' => $tfa->generateSecret(),
        'two_factor_recovery_codes' => ['keepme0-keepme0'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/** Spends $count wrong codes, stepping past the per-minute limiter between them. */
function guessWrongly(int $count): void
{
    foreach (range(1, $count) as $ignored) {
        test()->travel(20)->seconds();
        test()->post('/two-factor-challenge', ['code' => '000000']);
    }
}

beforeEach(function () {
    Sleep::fake();
    Notification::fake();

    $this->user = challengedUser();
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
});

it('stays silent while the failures still look like mistyping', function () {
    guessWrongly(4);

    Notification::assertNothingSent();
});

it('warns the account holder once the failures become a burst', function () {
    guessWrongly(5);

    Notification::assertSentTo($this->user, SecondFactorAttemptsDetected::class);
});

it('warns nobody but the account holder', function () {
    $bystander = challengedUser();

    guessWrongly(5);

    Notification::assertNotSentTo($bystander, SecondFactorAttemptsDetected::class);
});

it('does not warn again for the rest of the day, however long the attack runs', function () {
    // The attacker controls how many failures happen, so a per-burst signal that re-armed
    // would be a mail bomb against the very person it is meant to protect.
    guessWrongly(5);
    Notification::assertSentToTimes($this->user, SecondFactorAttemptsDetected::class, 1);

    $this->travel(2)->hours();
    guessWrongly(40);

    Notification::assertSentToTimes($this->user, SecondFactorAttemptsDetected::class, 1);
});

it('sends nothing when the correct code is entered', function () {
    $code = app(TwoFactorAuthenticator::class)->currentCode($this->user->two_factor_secret);

    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

it('keeps the challenge working when the warning cannot be sent', function () {
    // The warning is a courtesy; the challenge is the security control. A mail transport
    // that is missing or a queue that is down must never turn a wrong code into a 500 on
    // the login path — the user would simply be locked out by an unrelated outage.
    $this->app->bind(Dispatcher::class, function () {
        return new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('no mail transport configured');
            }

            /** @param  array<int, string>|null  $channels */
            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('no mail transport configured');
            }
        };
    });

    foreach (range(1, 5) as $attempt) {
        $this->travel(20)->seconds();
        $response = $this->from(route('two-factor.login'))
            ->post('/two-factor-challenge', ['code' => '000000']);
    }

    $response->assertRedirect(route('two-factor.login'))->assertSessionHasErrors('code');
    $this->assertGuest();
});
