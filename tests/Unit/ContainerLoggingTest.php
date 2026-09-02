<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Where log output goes is decided in three files that do not reference each other —
 * config/logging.php, phpunit.xml and docker/compose.yaml — and the failure mode is always
 * the same shape: something writes to an unbounded file, nobody notices, and the disk is
 * what eventually reports it. This checkout's own storage/logs/laravel.log reached 142 MB
 * that way, ~117k of its ~117k records written by the test suite.
 *
 * Nothing here asserts that a config value equals itself. Each test is a relation between
 * two files, or a sweep whose point is to fail on something *added later*:
 *
 * - a fifth application service in compose.yaml that forgets LOG_CHANNEL and so writes into
 *   a container layer Watchtower throws away;
 * - any service at all added without `logging:`, inheriting Docker's unbounded json-file;
 * - LOG_STACK's fallback going back to `single`, which is the state this branch repaired.
 *
 * The channel names are never matched against a literal like 'stderr'. They are resolved
 * through config/logging.php and judged on the property that actually matters — whether the
 * channel writes a file — so renaming or restructuring a channel cannot make these pass
 * vacuously.
 */

/**
 * @return array<string, mixed>
 */
function composeFile(): array
{
    return Yaml::parseFile(base_path('docker/compose.yaml'));
}

/**
 * config/logging.php as an installation that sets no LOG_* variables resolves it.
 *
 * Reading `config('logging')` instead would test the *test* environment: phpunit.xml sets
 * LOG_CHANNEL, and .env sets LOG_STACK, so the shipped fallbacks — the thing a fresh clone
 * and a CI runner actually get, and the thing that was wrong — would never be evaluated.
 *
 * @return array<string, mixed>
 */
function loggingConfigWithoutEnv(): array
{
    $names = ['LOG_CHANNEL', 'LOG_STACK', 'LOG_LEVEL', 'LOG_DAILY_DAYS', 'LOG_DEPRECATIONS_CHANNEL'];
    $saved = [];

    foreach ($names as $name) {
        $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    try {
        return require base_path('config/logging.php');
    } finally {
        foreach ($names as $name) {
            [$env, $server, $putenv] = $saved[$name];

            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($putenv !== false) {
                putenv("{$name}={$putenv}");
            }
        }
    }
}

/**
 * Every non-stack channel a channel name reaches, flattened. A `stack` is a fan-out, so
 * "does this channel write a file" is only answerable after following it.
 *
 * @param  array<string, mixed>  $channels
 * @return array<string, array<string, mixed>>
 */
function resolveLogChannels(string $name, array $channels, int $depth = 0): array
{
    $channel = $channels[$name] ?? null;

    if ($channel === null || $depth > 5) {
        return [];
    }

    if (($channel['driver'] ?? null) !== 'stack') {
        return [$name => $channel];
    }

    $leaves = [];

    foreach ($channel['channels'] ?? [] as $child) {
        $leaves += resolveLogChannels((string) $child, $channels, $depth + 1);
    }

    return $leaves;
}

it('keeps every service that runs the application image off a log file inside the container', function () {
    $compose = composeFile();
    $channels = config('logging.channels');
    $appImage = $compose['services']['app']['image'];

    $appServices = collect($compose['services'])
        ->filter(fn (array $service) => ($service['image'] ?? null) === $appImage);

    // Four today (app, worker, scheduler, reverb). A sweep that silently found none — a
    // renamed key, a per-service image — would pass every assertion below it.
    expect($appServices)->toHaveCount(4);

    foreach ($appServices as $name => $service) {
        $selected = $service['environment']['LOG_CHANNEL'] ?? null;

        // Not `env_file`, deliberately: Compose lets `environment:` win over `env_file:`, so
        // only a value written here survives an operator .env that still says `stack`.
        expect($selected)->not->toBeNull("service [{$name}] sets no LOG_CHANNEL");

        $leaves = resolveLogChannels((string) $selected, $channels);

        expect($leaves)->not->toBeEmpty("service [{$name}] names an undefined channel [{$selected}]");

        foreach ($leaves as $leaf => $config) {
            // storage/logs is not among the volumes, so any `path` here resolves inside the
            // container's writable layer: unbounded until a redeploy, and deleted by it —
            // which is the one moment an operator wants the log.
            expect(array_key_exists('path', $config))->toBeFalse(
                "service [{$name}] logs via [{$leaf}], which writes a file into the container",
            );
        }
    }
});

it('bounds what the Docker log driver keeps, for every service in the stack', function () {
    // Docker's default is json-file with no rotation at all: the file under
    // /var/lib/docker/containers grows until the container is removed, and a full partition
    // takes the daemon down with the stack. Sending the app to stderr (the test above) makes
    // this the *only* thing standing between a chatty container and the host disk.
    $services = composeFile()['services'];

    expect($services)->not->toBeEmpty();

    foreach ($services as $name => $service) {
        $logging = $service['logging'] ?? null;

        expect($logging)->not->toBeNull("service [{$name}] declares no logging options");

        // Only json-file and local are bounded by these two keys; a service switched to
        // another driver needs its own limits and should fail here rather than look covered.
        expect($logging['driver'] ?? 'json-file')->toBeIn(['json-file', 'local'], "service [{$name}] driver");

        $options = $logging['options'] ?? [];

        expect(array_key_exists('max-size', $options))->toBeTrue("service [{$name}] does not cap log file size")
            ->and(array_key_exists('max-file', $options))->toBeTrue("service [{$name}] does not cap log file count")
            ->and((int) $options['max-file'])->toBeGreaterThan(0, "service [{$name}] max-file")
            // A bare integer is a byte count; the driver wants a unit suffix and rejects the
            // stack at `up` time without one, which is a deploy-time failure this can catch.
            ->and((string) $options['max-size'])->toMatch('/^\d+[kmg]$/i', "service [{$name}] max-size");
    }
});

it('rotates on an installation that sets no LOG_ variables at all', function () {
    // .env.example is a template; nothing obliges an environment to have copied it, and the
    // ones that did not — CI, a fresh clone, a container with a half-filled .env — are
    // exactly where an unrotated file accumulates unobserved. The default in this file is
    // what those get, so the guarantee has to hold here and not only in .env.example.
    $logging = loggingConfigWithoutEnv();
    $leaves = resolveLogChannels((string) $logging['default'], $logging['channels']);

    expect($leaves)->not->toBeEmpty();

    $fileWriters = 0;

    foreach ($leaves as $name => $channel) {
        if (! array_key_exists('path', $channel)) {
            continue; // stderr, syslog, errorlog, null: nothing to grow.
        }

        $fileWriters++;
        // `days` is what the daily driver prunes by; `single` has no such key, which is
        // precisely why it never stops growing.
        expect(array_key_exists('days', $channel))->toBeTrue("default channel [{$name}] writes a file nothing rotates")
            ->and((int) ($channel['days'] ?? 0))->toBeGreaterThan(0, "channel [{$name}] retention");
    }

    // The default could legitimately become stderr-only one day, but until it does, a run
    // that found no file writer means the resolution above stopped working.
    expect($fileWriters)->toBeGreaterThan(0);
});

it('keeps the suite out of the log the developer reads', function () {
    // The 142 MB. A full run is ~1500 tests, many of them driving failure paths on purpose,
    // and every record went into the same storage/logs/laravel.log as real local requests.
    // Asserting the channel *name* from phpunit.xml would restate that file; what matters is
    // that the file it resolves to is neither the default channel's nor unrotated.
    $configured = env('LOG_CHANNEL');

    expect($configured)->not->toBeNull('phpunit.xml sets no LOG_CHANNEL');

    $suite = resolveLogChannels((string) $configured, config('logging.channels'));
    $default = resolveLogChannels((string) loggingConfigWithoutEnv()['default'], config('logging.channels'));

    expect($suite)->not->toBeEmpty();

    $suitePaths = collect($suite)->pluck('path')->filter()->values();
    $defaultPaths = collect($default)->pluck('path')->filter()->values();

    expect($suitePaths->intersect($defaultPaths))->toBeEmpty(
        'the suite writes into the same file as the default channel',
    );

    // Kept, not silenced: a failing test whose cause only shows up in a log entry has to
    // stay diagnosable. Which means this file needs its own retention.
    foreach ($suite as $name => $channel) {
        if (! array_key_exists('path', $channel)) {
            continue;
        }

        expect(array_key_exists('days', $channel))->toBeTrue("suite channel [{$name}] writes a file nothing rotates")
            ->and((int) ($channel['days'] ?? 0))->toBeGreaterThan(0, "suite channel [{$name}] retention");
    }
});
