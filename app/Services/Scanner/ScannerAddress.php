<?php

namespace App\Services\Scanner;

use App\Services\Upstream\UrlSafety;

/**
 * Whether the configured scanner may be dialled.
 *
 * The scanner is the one outbound target that legitimately lives on a private address — it
 * is a sibling container on the compose network — and `UrlSafety` refuses exactly that. The
 * answer is NOT to relax the address policy: it is the same explicit escape hatch the git
 * path already uses, an operator-entered host allowlist. A host nobody named stays refused
 * whether or not it happens to be the scanner.
 *
 * Mirrors GitUrlSafety::hostIsAllowlisted() in shape (exact match, or `*.suffix` covering
 * that suffix's subdomains) rather than inventing a second grammar for the same idea.
 */
final class ScannerAddress
{
    public static function isReachable(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return false;
        }

        // An allowlisted host skips the private-address check — and ONLY that check. It
        // still had to be a http(s) URL with a real host to get here.
        if (self::isAllowlisted($host)) {
            return true;
        }

        return UrlSafety::hostIsPublic($host);
    }

    private static function isAllowlisted(string $host): bool
    {
        /** @var array<int, string> $patterns */
        $patterns = (array) config('kontorfix.scanner.allowed_hosts', []);

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
