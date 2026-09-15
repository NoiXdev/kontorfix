<?php

// A token that does not resolve left no trace anywhere. Guessing one is hopeless — 238 bits
// behind a sha256 lookup — so this is not about catching a break-in; it is about an
// operator being able to see that something is scanning, or that a deployed client is
// hammering a revoked credential. The registry protocol routes carry no request throttle by
// design, so without a record there is nothing to notice either case by.
//
// The signal has to be narrow or it is noise: an ABSENT credential is the normal case on a
// public registry, and logging that would bury the interesting lines under every anonymous
// composer install. Only a credential that was PRESENTED and did not resolve is recorded.

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}>
 */
function captureTokenLog(): ArrayObject
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
function tokenLinesSaying(ArrayObject $lines, string $message): array
{
    return array_values(array_filter($lines->getArrayCopy(), fn (array $l): bool => $l[0] === $message));
}

it('records a registry token that was presented but did not resolve', function () {
    $group = Group::factory()->create(['public' => true]);
    $lines = captureTokenLog();

    $this->withToken('kfx_this_token_does_not_exist')
        ->get(registryPath($group).'/packages.json');

    $failures = tokenLinesSaying($lines, 'Registry credential rejected.');

    expect($failures)->toHaveCount(1)
        ->and($failures[0][1])->toHaveKey('ip');
});

it('never writes the presented credential into the log', function () {
    $group = Group::factory()->create(['public' => true]);
    $lines = captureTokenLog();

    $this->withToken('kfx_super_secret_value')->get(registryPath($group).'/packages.json');

    expect(json_encode($lines->getArrayCopy()))->not->toContain('kfx_super_secret_value');
});

it('stays silent for an anonymous request, which is the normal case on a public registry', function () {
    $group = Group::factory()->create(['public' => true]);
    $lines = captureTokenLog();

    $this->get(registryPath($group).'/packages.json')->assertOk();

    expect(tokenLinesSaying($lines, 'Registry credential rejected.'))->toBeEmpty();
});

it('stays silent for a token that resolves', function () {
    $group = Group::factory()->create(['public' => false]);
    $lines = captureTokenLog();

    $this->get(registryPath($group).'/packages.json', tokenHeaderFor($group))->assertOk();

    expect(tokenLinesSaying($lines, 'Registry credential rejected.'))->toBeEmpty();
});

it('deduplicates, so a client looping on a stale token cannot fill the disk', function () {
    // These routes carry no request budget by design — a cold composer install fires
    // hundreds — so one line per rejected request would be a log-amplification primitive
    // reachable by anyone. Same reasoning, and same window, as the lockout dedupe.
    $group = Group::factory()->create(['public' => true]);
    $lines = captureTokenLog();

    foreach (range(1, 12) as $ignored) {
        $this->withToken('kfx_stale')->get(registryPath($group).'/packages.json');
    }

    expect(tokenLinesSaying($lines, 'Registry credential rejected.'))->toHaveCount(1);
});

it('records a rejected api key the same way', function () {
    $lines = captureTokenLog();

    $this->withToken('kfxapi_not_a_real_key')->getJson('/api/v1/me')->assertUnauthorized();

    $failures = tokenLinesSaying($lines, 'API key rejected.');

    expect($failures)->toHaveCount(1)
        ->and(json_encode($failures))->not->toContain('kfxapi_not_a_real_key');
});

it('stays silent for an api key that resolves', function () {
    $user = User::factory()->create();
    [, $plain] = ApiKey::issue($user, 'k', ApiKeyPermission::Read);
    $lines = captureTokenLog();

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect(tokenLinesSaying($lines, 'API key rejected.'))->toBeEmpty();
});
