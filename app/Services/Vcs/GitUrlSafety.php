<?php

namespace App\Services\Vcs;

use App\Services\Upstream\UrlSafety;

/**
 * The address policy for every outbound git operation (ls-remote, clone, fetch).
 *
 * The clone URL is operator-supplied by design, so this is not an allowlist of
 * *repositories* — it restricts two things a repository URL should never be able to
 * decide on its own:
 *
 *  1. **The transport.** Only the schemes on `kontorfix.vcs.allowed_schemes` (by default
 *     `https` and `ssh`) reach git. This is an allowlist rather than a denylist because
 *     git's transport surface is open-ended: `file://` reads the container filesystem,
 *     `ext::` hands git an arbitrary shell command, and `git://` is unauthenticated
 *     cleartext. A denylist would have to keep up with git; an allowlist does not.
 *     Scheme-less forms (a bare path, the scp-like `git@host:path`) are refused for the
 *     same reason — there is nothing to check.
 *  2. **The address.** The host must resolve exclusively to public unicast addresses,
 *     using the same UrlSafety policy every other outbound fetcher in the application
 *     already applies. Without it the probe is a working internal port-scanner: it
 *     reports auth-failed / not-found / unreachable distinguishably, and returns real
 *     repository metadata when it does find an internal git server.
 *
 * An operator whose git server genuinely lives on a private network names it in
 * `kontorfix.vcs.allowed_hosts` (exact host, or `*.suffix`), which exempts that host from
 * the address check but not from the scheme check.
 *
 * This is enforced at the two sinks — RepositoryProbe::probe() and GitRepository::sync()
 * — rather than at the controllers, so the queued SyncPackage job, the packages:resync
 * command, the incoming-webhook trigger and the registry dist path are covered by
 * construction and cannot drift away from the guard.
 */
class GitUrlSafety
{
    public static function isSafe(string $url): bool
    {
        return self::reject($url) === null;
    }

    /**
     * The reason the URL is refused, or null when it is acceptable. The message names the
     * policy, never the target's reachability — it must not become the oracle it prevents.
     */
    public static function reject(string $url): ?string
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '' || ! in_array($scheme, self::allowedSchemes(), true)) {
            return 'Repository-URL abgelehnt: nur '.implode('/', self::allowedSchemes()).' sind als Git-Transport erlaubt.';
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        // A network transport without a host has nothing to check; a local transport
        // (only reachable when explicitly allowlisted above) legitimately has none.
        if ($host === '') {
            return self::isLocalScheme($scheme)
                ? null
                : 'Repository-URL abgelehnt: kein Host angegeben.';
        }

        if (self::hostIsAllowlisted($host)) {
            return null;
        }

        if (! UrlSafety::hostIsPublic($host)) {
            return 'Repository-URL abgelehnt: der Host zeigt auf eine interne oder reservierte Adresse.';
        }

        return null;
    }

    /** @return list<string> */
    private static function allowedSchemes(): array
    {
        /** @var array<int, string> $configured */
        $configured = (array) config('kontorfix.vcs.allowed_schemes', ['https', 'ssh']);

        $schemes = [];
        foreach ($configured as $scheme) {
            $scheme = strtolower(trim((string) $scheme));
            if ($scheme !== '') {
                $schemes[] = $scheme;
            }
        }

        // Never fail open on an empty or malformed configuration.
        return $schemes === [] ? ['https', 'ssh'] : array_values(array_unique($schemes));
    }

    /** Schemes that address the local filesystem and therefore carry no host. */
    private static function isLocalScheme(string $scheme): bool
    {
        return $scheme === 'file';
    }

    /** Exact host match, or a `*.suffix` wildcard covering that suffix's subdomains. */
    private static function hostIsAllowlisted(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        /** @var array<int, string> $patterns */
        $patterns = (array) config('kontorfix.vcs.allowed_hosts', []);

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }
}
