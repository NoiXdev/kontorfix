<?php

namespace App\Support;

/**
 * One source for which upstream addresses may speak for the client.
 *
 * The value decides who gets to set `X-Forwarded-For`, and therefore what every IP-keyed
 * limiter counts and what the audit log records as the origin of an action. It ships broad
 * — every RFC1918 range plus loopback — because the application cannot know an operator's
 * proxy address, and because a wrong value here breaks generated dist URLs rather than
 * failing safe. docs/development.md has always told operators to pin it; nothing surfaced
 * whether they had, which made the instruction advice rather than a control.
 *
 * That is what isBroad() is for: HealthService turns it into a visible check, so the gap
 * between the shipped default and the documented deployment is on screen rather than in a
 * paragraph. The breadth costs nothing against an internet attacker — Symfony's
 * trusted-proxy walk stops at the first untrusted address, which behind Traefik is the real
 * client — but anything that can reach the app from inside the private network, a
 * compromised co-tenant container, can forge both.
 *
 * It lives here rather than in config/ because bootstrap/app.php configures the middleware
 * before the config repository exists and has to read the environment directly. Sharing a
 * constant is what keeps the check and the middleware talking about the same value.
 */
final class TrustedProxies
{
    /** Every private range plus loopback — see the class docblock for why it is this wide. */
    public const DEFAULT = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1';

    /**
     * An IPv4 prefix at or below this length covers more hosts than any proxy pool needs.
     * /24 is 256 addresses; /8 is sixteen million.
     */
    private const BROAD_IPV4_PREFIX = 24;

    private const BROAD_IPV6_PREFIX = 120;

    /**
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $e): bool => $e !== ''));
    }

    /**
     * Whether the configured set is wider than a pinned proxy — or absent, which trusts
     * nothing and quietly breaks the forwarded scheme and host instead.
     */
    public static function isBroad(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            return true;
        }

        foreach (self::parse($value) as $entry) {
            if (! str_contains($entry, '/')) {
                continue;
            }

            [$network, $prefix] = explode('/', $entry, 2);
            if (! is_numeric($prefix)) {
                return true;
            }

            $limit = str_contains($network, ':') ? self::BROAD_IPV6_PREFIX : self::BROAD_IPV4_PREFIX;
            if ((int) $prefix < $limit) {
                return true;
            }
        }

        return false;
    }
}
