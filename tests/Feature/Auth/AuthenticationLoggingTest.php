<?php

// A login brute force used to leave no record at all: `Failed` and `Lockout` were
// dispatched into a void, no listener existed, and nothing was written anywhere — while
// the login throttle's own docblock named that monitoring as the compensating control for
// the traffic it deliberately does not refuse. These tests pin the record itself, and pin
// the two things the record must never become: a plaintext password file, and an
// anonymously drivable way to fill a disk.

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/** Collects every line the application writes, so assertions can be made on content. */
function captureLogLines(): ArrayObject
{
    $lines = new ArrayObject;

    Log::swap(new class($lines) implements LoggerInterface
    {
        use LoggerTrait;

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
 * @return array<int, array{0: string, 1: array<string, mixed>}>
 */
function linesSaying(ArrayObject $lines, string $message): array
{
    return array_values(array_filter(
        $lines->getArrayCopy(),
        fn (array $line): bool => $line[0] === $message,
    ));
}

beforeEach(function () {
    Sleep::fake();
});

it('writes a line for every failed login', function () {
    $user = User::factory()->create();
    $lines = captureLogLines();

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $failures = linesSaying($lines, 'Authentication failed.');

    expect($failures)->toHaveCount(1)
        ->and($failures[0][1]['email'])->toBe($user->email)
        ->and($failures[0][1]['user_id'])->toBe($user->id)
        ->and($failures[0][1]['path'])->toBe('login');
});

it('never writes the submitted password into the log', function () {
    $user = User::factory()->create();
    $lines = captureLogLines();

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    // Anchor first: a "the log does not contain X" assertion passes gloriously against an
    // empty log, so pin that the request really did reach the listener before claiming
    // anything about what the listener left out.
    expect(linesSaying($lines, 'Authentication failed.'))->toHaveCount(1);
    expect(json_encode($lines->getArrayCopy()))->not->toContain('correct-horse-battery-staple');

    // The application's own two dispatchers pass only the addressee, so the line above
    // would stay clean even if the listener wrote the whole array. `Failed::$credentials`
    // is the framework's contract, though, and Auth::attempt() puts the *password* in it —
    // one call site switching to it would otherwise turn this log into a plaintext
    // password file, with the victim's real password on the attempt that finally lands.
    event(new Failed('web', $user, [
        'email' => $user->email,
        'password' => 'hunter2-from-a-framework-dispatcher',
    ]));

    expect(linesSaying($lines, 'Authentication failed.'))->toHaveCount(2)
        ->and(json_encode($lines->getArrayCopy()))->not->toContain('hunter2-from-a-framework-dispatcher');
});

it('records a login that was refused rather than compared', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $lines = captureLogLines();

    // Refused by the per-(email, IP) counter: no comparison happens, so `Failed` cannot
    // carry this one. Without the Lockout half, the loudest event on the endpoint would
    // be the one that vanishes.
    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $throttled = linesSaying($lines, 'Authentication throttled.');

    expect($throttled)->toHaveCount(1)
        ->and($throttled[0][1]['email'])->toBe($user->email)
        ->and($throttled[0][1]['ip'])->toBe('127.0.0.1');
});

it('does not let an anonymous caller drive one log line per request', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $lines = captureLogLines();

    // Twenty further refusals from the same source against the same target. Every one of
    // them raises Lockout, and every one of them is anonymous and free — so a line each
    // would be a log-amplification primitive handed to the attacker.
    foreach (range(1, 20) as $i) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $throttled = linesSaying($lines, 'Authentication throttled.');

    expect($throttled)->toHaveCount(1);
});

it('records a failed password confirmation behind a session too', function () {
    $user = User::factory()->create();
    $lines = captureLogLines();

    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    $failures = linesSaying($lines, 'Authentication failed.');

    expect($failures)->toHaveCount(1)
        ->and($failures[0][1]['user_id'])->toBe($user->id)
        ->and($failures[0][1]['path'])->toBe('confirm-password');
});
