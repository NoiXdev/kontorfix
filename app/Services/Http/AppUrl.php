<?php

namespace App\Services\Http;

/**
 * The single reading of `APP_URL`, for every control that derives a host or a URL root
 * from it.
 *
 * Four call sites used to run their own `parse_url((string) config('app.url'), …)`, and
 * they all shared one failure: `parse_url('registry.example.com', PHP_URL_HOST)` returns
 * **null**, because a value without a scheme parses as a path. A missing `https://` — one
 * character class in one environment variable — therefore switched off the `Host`
 * allowlist, the pinning of the generated-URL root, the reserved-hostname rule that keeps
 * the console host out of the `domains` table, and the absolute base of the registry's own
 * download URLs, all at once, with no boot check, no log line and no health signal. A
 * misconfiguration that silently removes several security controls together is worse than
 * any of the individual gaps.
 *
 * So a scheme-less value is normalised to `https://…` instead of being treated as absent.
 * `https` and not `http`: the deployment this project documents terminates TLS at the
 * proxy, and if the guess is wrong the consequence is a link on the wrong scheme, whereas
 * guessing `http` would silently downgrade every generated link.
 *
 * `null` is returned only when the value names no host at all — unset, empty, or a bare
 * path. That case still fails open (see TrustedHosts and PinUrlRoot: locking an instance
 * out of itself over an unset variable is the worse bug), but it is no longer silent —
 * HealthService reports it.
 */
final class AppUrl
{
    /**
     * The configured application root, normalised: scheme, host, optional port and any
     * subdirectory path, without a trailing slash. Null when APP_URL names no host.
     */
    public static function root(): ?string
    {
        $normalised = self::normalise();

        if ($normalised === null) {
            return null;
        }

        return rtrim($normalised, '/');
    }

    /**
     * The host APP_URL names, lowercased. Null when it names none.
     */
    public static function host(): ?string
    {
        $normalised = self::normalise();

        if ($normalised === null) {
            return null;
        }

        $host = parse_url($normalised, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Returns the configured value with a scheme, or null if no host can be recovered.
     */
    private static function normalise(): ?string
    {
        $raw = trim((string) config('app.url'));

        if ($raw === '') {
            return null;
        }

        // `//host/path` and `host:8443` are both scheme-less but do name a host; so is a
        // plain `host`. Anything already carrying a scheme is left exactly as written —
        // including a non-http one, which then yields no usable host below.
        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $raw)) {
            $raw = 'https://'.ltrim($raw, '/');
        }

        $host = parse_url($raw, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $raw : null;
    }
}
