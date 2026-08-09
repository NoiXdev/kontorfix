<?php

use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Health\HealthService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;

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

// The secret guard's own precondition: an instance that actually broadcasts over
// Reverb. The driver is asserted separately below.
function broadcastOverReverb(): void
{
    config(['broadcasting.default' => 'reverb']);
}

it('refuses to start the websocket server with the secret published in this repository', function () {
    pretendEnvironment('production');
    broadcastOverReverb();
    config(['reverb.apps.apps.0.secret' => 'kontorfix-secret']);

    expect(fn () => startReverb())->toThrow(RuntimeException::class, 'REVERB_APP_SECRET');
});

it('refuses to start the websocket server without any secret at all', function () {
    pretendEnvironment('production');
    broadcastOverReverb();

    foreach (['', '   ', null] as $secret) {
        config(['reverb.apps.apps.0.secret' => $secret]);
        expect(fn () => startReverb())->toThrow(RuntimeException::class, 'REVERB_APP_SECRET');
    }
});

it('starts the websocket server once a real secret is configured', function () {
    pretendEnvironment('production');
    broadcastOverReverb();
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

it('confirms a usable websocket configuration instead of staying silent about it', function () {
    // Silence used to be the only "all good" signal, which is indistinguishable from
    // "the check never ran". An operator has to be able to see that it did.
    pretendEnvironment('production');
    config([
        'broadcasting.default' => 'reverb',
        'reverb.apps.apps.0.secret' => 'b0e2c1a9f7d34e5f8a1b2c3d4e5f6071',
    ]);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'broadcasting');

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeTrue();
});

it('refuses to start a websocket server that broadcasting does not use', function () {
    // A reverb container on an instance whose driver is `null` relays nothing: it is
    // unauthenticated surface with no purpose. Refusing names the missing setting
    // rather than the secret, so the log line diagnoses the actual misconfiguration.
    pretendEnvironment('production');
    config([
        'broadcasting.default' => 'null',
        'reverb.apps.apps.0.secret' => 'b0e2c1a9f7d34e5f8a1b2c3d4e5f6071',
    ]);

    expect(fn () => startReverb())->toThrow(RuntimeException::class, 'BROADCAST_CONNECTION');
});

it('reports a refused websocket container on the health page even when the driver is not reverb', function () {
    // The crash loop this closes was invisible precisely because the health check
    // short-circuited on the driver. The websocket container records why it refused;
    // the app container reads that back, whatever the driver says.
    pretendEnvironment('production');
    config([
        'broadcasting.default' => 'null',
        'reverb.apps.apps.0.secret' => 'b0e2c1a9f7d34e5f8a1b2c3d4e5f6071',
    ]);

    expect(fn () => startReverb())->toThrow(RuntimeException::class);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'broadcasting');

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('BROADCAST_CONNECTION');
});

it('clears a recorded refusal once the websocket server comes up', function () {
    pretendEnvironment('production');
    config(['broadcasting.default' => 'null', 'reverb.apps.apps.0.secret' => 'kontorfix-secret']);
    expect(fn () => startReverb())->toThrow(RuntimeException::class);

    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.secret' => 'b0e2c1a9f7d34e5f8a1b2c3d4e5f6071']);
    startReverb();

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'broadcasting');

    expect($check['ok'])->toBeTrue();
});

it('does not start the websocket container in the shipped compose default', function () {
    // docker/.env.example ships the whole broadcasting block commented out, so the
    // stock instance runs the `null` driver. A reverb service started unconditionally
    // on that instance can only refuse — forever, under `restart: unless-stopped`.
    // The service therefore has to be opt-in, exactly like the env block it needs.
    $compose = Yaml::parseFile(base_path('docker/compose.yaml'));
    $reverb = $compose['services']['reverb'];

    expect($reverb['profiles'] ?? [])->toContain('reverb')
        ->and($reverb['restart'] ?? '')->not->toBe('unless-stopped');
});

it('names the exact published values it refuses', function () {
    // Pinned so that removing a value from the deny list is a visible test change and
    // not a silent re-opening of the finding.
    expect(ReverbConfigGuard::PUBLISHED_SECRETS)->toContain('kontorfix-secret');
});
