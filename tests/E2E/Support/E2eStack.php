<?php

namespace Tests\E2E\Support;

use GuzzleHttp\Client;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The one place that knows how to reach the E2E stack.
 *
 * Everything else in tests/E2E asks this class, so there is a single statement of the
 * compose file's location, of where the context lives, and of how a client container is
 * driven. These tests do not boot Laravel: there is no container to resolve anything from,
 * and no test database to refresh.
 */
final class E2eStack
{
    /** @var array<string, string>|null */
    private static ?array $context = null;

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string>
     */
    public static function context(): array
    {
        if (self::$context !== null) {
            return self::$context;
        }

        $path = self::root().'/tests/E2E/.context.json';

        if (! is_file($path)) {
            throw new RuntimeException(
                "No E2E context at {$path}. These tests run through `bin/e2e`, which brings the "
                .'stack up and seeds it; running pest against phpunit.e2e.xml directly cannot work.'
            );
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return self::$context = $decoded;
    }

    /**
     * Runs a shell script inside one of the client containers. `-T` because there is no TTY
     * in CI; `sh -c` rather than a bare argv so a test can express a small pipeline.
     */
    public static function exec(string $service, string $script, int $timeout = 300): Process
    {
        $process = new Process(
            ['docker', 'compose', '-f', 'docker/compose.e2e.yaml', 'exec', '-T', $service, 'sh', '-c', $script],
            self::root(),
            null,
            null,
            (float) $timeout,
        );

        $process->run();

        return $process;
    }

    /**
     * A direct read of the registry from the host, over the published loopback port. Used
     * for the assertions the clients cannot make — above all the refusal tests, which have
     * to establish that the answer was 401 and not merely that a command failed.
     *
     * @return array{status: int, body: string}
     */
    public static function get(string $path, ?string $token = null): array
    {
        $client = new Client(['http_errors' => false, 'timeout' => 30]);

        $response = $client->get(self::context()['host_base_url'].$path, [
            'headers' => $token !== null ? ['Authorization' => 'Bearer '.$token] : [],
        ]);

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Waits for the queued SyncPackage to finish, by asking the registry the question a
     * Composer client asks: does this package have versions yet?
     *
     * Deliberately black-box. Polling `packages.sync_status` would mean either shelling into
     * Postgres or adding an artisan command that exists only for tests; the metadata endpoint
     * is the signal a real client sees, and it is the one that has to become true.
     *
     * @return array<string, mixed> the package's version list
     */
    public static function waitForComposerVersions(string $package, int $seconds = 180): array
    {
        $deadline = time() + $seconds;
        $last = 'never queried';

        while (time() < $deadline) {
            $response = self::get("/p2/{$package}.json", self::context()['read_token']);

            if ($response['status'] === 200) {
                /** @var array{packages?: array<string, array<int, mixed>>} $doc */
                $doc = json_decode($response['body'], true) ?: [];
                $versions = $doc['packages'][$package] ?? [];

                if ($versions !== []) {
                    return $versions;
                }

                $last = 'metadata served, version list still empty';
            } else {
                $last = 'HTTP '.$response['status'];
            }

            sleep(2);
        }

        throw new RuntimeException("Timed out after {$seconds}s waiting for {$package} to sync ({$last}).");
    }
}
