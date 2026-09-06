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
     * The npm config key under which the auth token for this registry must be set.
     *
     * npm's own nerf-dart algorithm (`@npmcli/config`'s `nerfDart()`) resolves the
     * credential key by taking the URL's directory — `new URL('.', registry)` — so a
     * registry URL without a trailing slash treats its last path segment as a filename and
     * drops it: the auth key would end up keyed one path segment shorter than the URL npm
     * actually requests against, and every request goes out unauthenticated. Confirmed
     * against the real client: without the trailing slash, `npm publish` failed with
     * ENEEDAUTH even though the token was set under what looked like the matching key.
     *
     * Centralized here rather than duplicated per test file: two independent copies of a
     * rule that was found by experiment, and that fails silently (ENEEDAUTH, not a config
     * error) when wrong, is exactly the shape that drifts the next time someone touches it.
     * Task 6 needs the identical key against the identical registry.
     */
    public static function npmAuthKey(): string
    {
        $registry = rtrim(self::context()['base_url'], '/').'/';

        return str_replace('http:', '', $registry).':_authToken';
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
     * Scheme+host(+port) only, no path — for endpoints that sit outside the registry prefix
     * (`/up`). `host_base_url` in the context always carries the registry's own path suffix
     * (`/r/<org>/<registry>`), which a health-check endpoint at the site root does not share.
     */
    public static function hostRoot(): string
    {
        $parts = parse_url(self::context()['host_base_url']);

        $root = "{$parts['scheme']}://{$parts['host']}";

        return isset($parts['port']) ? "{$root}:{$parts['port']}" : $root;
    }

    /**
     * pip's `--index-url` embeds credentials as URL userinfo (`http://x:<token>@host/...`),
     * built here from `base_url` rather than repeated as a literal `app:8080` in every test
     * that needs one. Three call sites (PypiTest.php's install and refusal scripts,
     * UpstreamTest.php's PyPI fallthrough) had the host and registry path spelled out by
     * hand; a slug change in the seeder would have had to be echoed into all three by hand
     * as well, and Composer's and npm's own tests already read `base_url` directly rather
     * than duplicating it — pip was the odd one out only because embedding a credential in
     * the URL itself isn't something the other two ecosystems' clients need.
     */
    public static function pipIndexUrl(?string $token = null): string
    {
        $credential = $token !== null ? "x:{$token}@" : '';

        // Not preg_replace('#^(https?://)#', '$1'.$credential, ...): the token lands in the
        // *replacement* string there, where `$` and `\` are backreference syntax. A token
        // that happened to contain either would be silently mangled rather than inserted
        // verbatim — str_replace has no such reading of its replacement argument.
        $baseUrl = self::context()['base_url'];
        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);

        return str_replace("{$scheme}://", "{$scheme}://{$credential}", $baseUrl).'/simple';
    }

    /**
     * Turns an absolute registry URL — as it appears inside p2/packument metadata, always
     * built from APP_URL (`http://app:8080/...`, the address the app and worker containers
     * see each other at) — into the path suffix `get()` needs. `get()` reaches the stack from
     * the host over the published loopback port under `host_base_url`
     * (`http://127.0.0.1:8099/...`) instead, but both share the exact same path structure
     * under the registry prefix; only the host differs. Exists so a test that needs to follow
     * a URL out of a metadata response (e.g. a dist URL) asks this rather than hand-assembling
     * one from string replacement.
     */
    public static function pathFromRegistryUrl(string $absoluteUrl): string
    {
        $prefix = (string) parse_url(self::context()['base_url'], PHP_URL_PATH);
        $path = (string) parse_url($absoluteUrl, PHP_URL_PATH);

        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException(
                "Registry URL {$absoluteUrl} does not share the expected path prefix {$prefix}."
            );
        }

        return substr($path, strlen($prefix));
    }

    /**
     * Waits for the queued SyncPackage to finish, by asking the registry the question a
     * Composer client asks: does this package have versions yet?
     *
     * Deliberately black-box. Polling `packages.sync_status` would mean either shelling into
     * Postgres or adding an artisan command that exists only for tests; the metadata endpoint
     * is the signal a real client sees, and it is the one that has to become true.
     *
     * @return list<array<string, mixed>> the package's version list
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
