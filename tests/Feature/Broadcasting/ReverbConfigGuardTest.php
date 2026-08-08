<?php

use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Health\HealthService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A05 — the Pusher protocol verifies private-channel subscriptions *inside* the
 * websocket server, against the app secret alone. routes/channels.php is never
 * consulted for a raw wss:// client. So the secret is the only control standing
 * between an anonymous internet client and every tenant's sync traffic, and this
 * repository published a literal one (`kontorfix-secret`) as the sole concrete
 * value an operator could copy.
 *
 * The guard therefore has to hold two properties at once:
 *   1. the websocket server refuses to start outside local development while the
 *      secret is empty or is a value this repository has published, and
 *   2. local development and the test suite keep working without ceremony.
 */
function startReverb(string $command = 'reverb:start'): void
{
    Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));
}

function pretendEnvironment(string $env): void
{
    app()->detectEnvironment(fn (): string => $env);
}

it('refuses to start the websocket server with the secret published in this repository', function () {
    pretendEnvironment('production');
    config(['reverb.apps.apps.0.secret' => 'kontorfix-secret']);

    expect(fn () => startReverb())->toThrow(RuntimeException::class, 'REVERB_APP_SECRET');
});

it('refuses to start the websocket server without any secret at all', function () {
    pretendEnvironment('production');

    foreach (['', '   ', null] as $secret) {
        config(['reverb.apps.apps.0.secret' => $secret]);
        expect(fn () => startReverb())->toThrow(RuntimeException::class, 'REVERB_APP_SECRET');
    }
});

it('starts the websocket server once a real secret is configured', function () {
    pretendEnvironment('production');
    config(['reverb.apps.apps.0.secret' => 'b0e2c1a9f7d34e5f8a1b2c3d4e5f6071']);

    startReverb();
})->throwsNoExceptions();

it('leaves local development and the test suite alone', function () {
    config(['reverb.apps.apps.0.secret' => 'kontorfix-secret']);

    foreach (['local', 'testing'] as $env) {
        pretendEnvironment($env);
        startReverb();
    }
})->throwsNoExceptions();

it('does not block commands other than the websocket server', function () {
    pretendEnvironment('production');
    config(['reverb.apps.apps.0.secret' => 'kontorfix-secret']);

    startReverb('migrate');
    startReverb('queue:work');
})->throwsNoExceptions();

it('does not accept a websocket handshake from every origin', function () {
    // Reverb returns early on `*` and never looks at the Origin header again
    // (Protocols\Pusher\Server::handle). The shipped value has to name a host.
    $origins = config('reverb.apps.apps.0.allowed_origins');

    expect($origins)->not->toContain('*')
        ->and($origins)->toBe([parse_url((string) config('app.url'), PHP_URL_HOST)]);
});

it('reports the published secret to the operator on the health page', function () {
    pretendEnvironment('production');
    config([
        'broadcasting.default' => 'reverb',
        'reverb.apps.apps.0.secret' => 'kontorfix-secret',
    ]);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'broadcasting');

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('REVERB_APP_SECRET');
});

it('keeps quiet on the health page when broadcasting does not use reverb', function () {
    pretendEnvironment('production');
    config([
        'broadcasting.default' => 'null',
        'reverb.apps.apps.0.secret' => 'kontorfix-secret',
    ]);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'broadcasting');

    expect($check)->toBeNull();
});

it('names the exact published values it refuses', function () {
    // Pinned so that removing a value from the deny list is a visible test change and
    // not a silent re-opening of the finding.
    expect(ReverbConfigGuard::PUBLISHED_SECRETS)->toContain('kontorfix-secret');
});
