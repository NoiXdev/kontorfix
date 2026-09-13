<?php

// Guessing at the second factor was both unbounded in total and completely traceless.
//
// The 5-per-minute limiter is a rate, not a ceiling: it rolls, and nothing counted attempts
// across windows. With a ±1 step tolerance three codes are valid at any moment, so five
// guesses a minute is ~7,200 a day and a median of about a month to land one — against an
// attacker who already holds the password. And unlike the password stage, which fires
// `Failed` and `Lockout` into LogAuthenticationEvent, this branch raised nothing at all: no
// log line, no event, no signal to the account holder. `docker/Caddyfile` configures no
// access log either, so there was no compensating layer underneath.

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}>
 */
function captureChallengeLog(): ArrayObject
{
    /** @var ArrayObject<int, array{0: string, 1: array<string, mixed>}> $lines */
    $lines = new ArrayObject;

    Log::swap(new class($lines) implements LoggerInterface
    {
        use LoggerTrait;

        /** @param ArrayObject<int, array{0: string, 1: array<string, mixed>}> $lines */
        public function __construct(private readonly ArrayObject $lines) {}

        /** @param array<string, mixed> $context */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [(string) $message, $context];
        }
    });

    return $lines;
}

/**
 * @param  ArrayObject<int, array{0: string, 1: array<string, mixed>}>  $lines
 * @return array<int, array{0: string, 1: array<string, mixed>}>
 */
function challengeLinesSaying(ArrayObject $lines, string $message): array
{
    return array_values(array_filter($lines->getArrayCopy(), fn (array $l): bool => $l[0] === $message));
}

function twoFactorUserAtChallenge(): User
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

beforeEach(function () {
    Sleep::fake();
});

it('writes a line for a wrong second factor, the way the password stage does', function () {
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $lines = captureChallengeLog();
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    $failures = challengeLinesSaying($lines, 'Authentication failed.');

    expect($failures)->toHaveCount(1)
        ->and($failures[0][1]['user_id'])->toBe($user->id)
        ->and($failures[0][1]['path'])->toBe('two-factor-challenge');
});

it('never writes the submitted code into the log', function () {
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $lines = captureChallengeLog();
    $this->post('/two-factor-challenge', ['code' => '424242']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'secret1-secret2']);

    $dumped = json_encode($lines->getArrayCopy());

    expect($dumped)->not->toContain('424242')
        ->and($dumped)->not->toContain('secret1-secret2');
});

it('signals a burst once, rather than on every refused request', function () {
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $lines = captureChallengeLog();
    foreach (range(1, 8) as $ignored) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    // The refusal is reachable repeatedly, so a line per refused request would be a
    // log-amplification primitive — the same reason LogAuthenticationEvent deduplicates.
    expect(challengeLinesSaying($lines, 'Authentication throttled.'))->toHaveCount(1);
});

it('bounds guessing in total, not only per minute', function () {
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    // Spend the daily allowance while stepping past the per-minute limiter, which is a
    // rate and was never the ceiling.
    foreach (range(1, TwoFactorChallengeController::DAILY_ATTEMPT_CAP) as $i) {
        $this->travel(20)->seconds();
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    // A fresh minute no longer buys a fresh allowance...
    $this->travel(2)->minutes();
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    // ...and re-authenticating does not reset it either: the ceiling is bound to the
    // account, not to the challenge session the attacker controls.
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('lets the account holder back in once the daily window rolls off', function () {
    // The ceiling must not become a permanent lockout an attacker can inflict at will.
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, TwoFactorChallengeController::DAILY_ATTEMPT_CAP) as $i) {
        $this->travel(20)->seconds();
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->travel(25)->hours();

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('does not spend the daily allowance on a correct code', function () {
    $user = twoFactorUserAtChallenge();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    expect(RateLimiter::attempts(
        TwoFactorChallengeController::dailyThrottleKey($user->id)
    ))->toBe(0);
});
