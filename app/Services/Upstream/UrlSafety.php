<?php

namespace App\Services\Upstream;

class UrlSafety
{
    /**
     * Whether an artifact URL (delivered by the upstream) may be safely fetched:
     * only http/https, no IP literal in private/reserved ranges, no localhost.
     * Prevents second-order SSRF via a compromised/typosquatted upstream URL
     * — applies to the initial URL AND every redirect hop.
     */
    public static function isSafe(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        // Remove brackets/zone index so IPv6 literals (http://[::1]/) are recognized
        // as an IP — parse_url otherwise returns the host including brackets.
        $host = self::normalizeHost($host);

        // Reject IP literals in private/reserved ranges (IPv4 AND IPv6).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::ipIsPublic($host);
        }

        return true;
    }

    /**
     * Like isSafe(), additionally with DNS resolution: a hostname that (also) points
     * to a private/reserved address is rejected — this closes SSRF via internal
     * hostnames (e.g. a malicious OIDC discovery document with token_endpoint pointing
     * to http://vault.internal) as well as octal/decimal IP encodings that filter_var
     * treats as hostnames. Hosts that don't resolve are allowed through (the HTTP
     * fetch then fails harmlessly anyway, since there's no internal target).
     *
     * Note: does not protect against DNS rebinding (TOCTOU) — that would require a
     * resolver pinned to cURL; this is noted as a follow-up.
     */
    public static function isSafeResolving(?string $url): bool
    {
        if (! self::isSafe($url)) {
            return false;
        }

        $host = self::normalizeHost((string) parse_url((string) $url, PHP_URL_HOST));

        // isSafe() has already checked IP literals (including bracketed IPv6).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        foreach (self::resolveIps($host) as $ip) {
            if (! self::ipIsPublic($ip)) {
                return false;
            }
        }

        return true;
    }

    /** Whether an IP address lies outside private/reserved ranges. */
    public static function ipIsPublic(string $ip): bool
    {
        // Defensive: in case brackets/a zone index come along.
        $ip = self::normalizeHost($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Break IPv4-mapped IPv6 (::ffff:a.b.c.d, ::ffff:0:0/96) down to the embedded
        // IPv4 and check it like IPv4 — otherwise e.g. ::ffff:169.254.169.254
        // (cloud metadata) and ::ffff:10.0.0.1 would slip through.
        $mapped = self::mappedIpv4($ip);
        if ($mapped !== null) {
            $ip = $mapped;
        }

        // Covers private + reserved ranges for both IPv4 AND IPv6.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // filter_var's IPv4 reserved set has holes; close the ones that can address a
        // real neighbour on the deployment network. Verified empirically to pass the
        // filter_var check above on PHP 8.4/8.5.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! self::ipv4IsPublic($ip)) {
            return false;
        }

        // Explicit IPv6 addition: older PHP versions only cover these ranges
        // patchily with FILTER_FLAG_NO_RES_RANGE. Belt and suspenders.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($ip);
            if (! is_string($packed) || strlen($packed) !== 16) {
                return false;
            }

            // :: (unspecified) and ::1 (loopback)
            if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15)."\x01") {
                return false;
            }

            $first = ord($packed[0]);

            // fc00::/7 (Unique Local Addresses, incl. fd00::/8)
            if (($first & 0xFE) === 0xFC) {
                return false;
            }

            // fe80::/10 (Link-Local)
            if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
                return false;
            }
        }

        return true;
    }

    /**
     * IPv4 ranges that FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE lets through even though
     * they are not globally routable unicast, and each of which can name a neighbour on
     * a real deployment network:
     *   - 100.64.0.0/10   shared address space / CGNAT (Tailscale, several managed CNIs)
     *   - 198.18.0.0/15   benchmarking
     *   - 224.0.0.0/4     multicast
     *   - 192.0.0.0/24    IETF protocol assignments
     */
    private static function ipv4IsPublic(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        // [network, prefix length] pairs, compared on the masked address.
        $blocked = [
            ['100.64.0.0', 10],
            ['198.18.0.0', 15],
            ['224.0.0.0', 4],
            ['192.0.0.0', 24],
        ];

        foreach ($blocked as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            if ((($long & $mask) & 0xFFFFFFFF) === ((int) ip2long($network) & $mask & 0xFFFFFFFF)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize the host for the IP check: remove square brackets from IPv6
     * literals (http://[::1]/ → parse_url returns "[::1]") and any zone index
     * (fe80::1%eth0). A no-op for ordinary hostnames/IPv4.
     */
    private static function normalizeHost(string $host): string
    {
        $host = trim($host, '[]');

        $zone = strpos($host, '%');
        if ($zone !== false) {
            $host = substr($host, 0, $zone);
        }

        return $host;
    }

    /**
     * Returns the embedded IPv4 of an IPv6 address with IPv4 in the last 32 bits,
     * otherwise null. Covers three /96 embeddings that would otherwise slip through
     * as "public" and could point to internal IPv4 targets via gateway/stack:
     *   - ::ffff:0:0/96   IPv4-mapped
     *   - ::/96           IPv4-compatible (deprecated)
     *   - 64:ff9b::/96    NAT64 well-known prefix
     */
    private static function mappedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if (! is_string($packed) || strlen($packed) !== 16) {
            return null;
        }

        $prefix12 = substr($packed, 0, 12);

        $isEmbedded = $prefix12 === str_repeat("\x00", 10)."\xff\xff"   // ::ffff:0:0/96
            || $prefix12 === str_repeat("\x00", 12)                     // ::/96
            || $prefix12 === "\x00\x64\xff\x9b".str_repeat("\x00", 8);  // 64:ff9b::/96

        if ($isEmbedded) {
            $v4 = @inet_ntop(substr($packed, 12, 4));

            return is_string($v4) ? $v4 : null;
        }

        return null;
    }

    /**
     * All A and AAAA addresses of a hostname (empty if not resolvable).
     *
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }
}
