<?php

namespace App\Support;

/**
 * The single answer to "where would this token be sent".
 *
 * `GitAuth::origin()` scopes the Authorization header to `scheme://host:port`, so the port
 * is part of the destination, not decoration on it. Two columns carry a git credential —
 * `git_credentials.host` and `packages.repository_token` — and each had its own comparison.
 * `GitCredential::permits()` was made authority-aware; `PackageController::sameHost()` was
 * left on `parse_url(..., PHP_URL_HOST)`, which discards the port by construction, so a
 * Maintainer could retarget a package to another port of the same host, keep the stored PAT
 * and have it delivered to a listener they control. One column answering the question
 * differently from the other is how that survived, so both now ask this.
 */
final class RepositoryAuthority
{
    /**
     * `host[:port]` for a repository URL, or null when the value carries no host at all.
     *
     * Null is "cannot be established", never "matches anything": callers must treat it as a
     * refusal rather than as a wildcard.
     */
    public static function of(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';

        return self::normalize($parts['host'], isset($parts['port']) ? (int) $parts['port'] : null, $scheme);
    }

    /**
     * `host[:port]` with the scheme's default port dropped, so that `gitlab.corp` and
     * `https://gitlab.corp:443/...` agree while `https://gitlab.corp:9999/...` does not.
     * A port embedded in the host string is parsed out of it the same way, which is what
     * lets an operator write `gitlab.example:8443` into a bare host column.
     */
    public static function normalize(string $host, ?int $port, string $scheme): string
    {
        $host = strtolower(trim($host));

        if ($port === null && str_contains($host, ':')) {
            [$host, $rawPort] = explode(':', $host, 2);
            $port = ctype_digit($rawPort) ? (int) $rawPort : null;
        }

        $default = match (strtolower($scheme)) {
            'http' => 80,
            'ssh', 'git+ssh' => 22,
            default => 443,
        };

        return $port === null || $port === $default ? $host : $host.':'.$port;
    }
}
