<?php

namespace App\Services\Upstream;

class UrlSafety
{
    /**
     * Ob eine (vom Upstream gelieferte) Artefakt-URL sicher abgerufen werden darf:
     * nur http/https, kein IP-Literal in privaten/reservierten Bereichen, kein localhost.
     * Verhindert Second-Order-SSRF durch eine kompromittierte/typosquattete Upstream-URL
     * — gilt für die initiale URL UND jeden Redirect-Hop.
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

        // Klammern/Zonen-Index entfernen, damit IPv6-Literale (http://[::1]/) als IP
        // erkannt werden — parse_url liefert den Host sonst inklusive Klammern.
        $host = self::normalizeHost($host);

        // IP-Literale in privaten/reservierten Bereichen ablehnen (IPv4 UND IPv6).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::ipIsPublic($host);
        }

        return true;
    }

    /**
     * Wie isSafe(), zusätzlich mit DNS-Auflösung: ein Hostname, der (auch) auf eine
     * private/reservierte Adresse zeigt, wird abgelehnt — schließt SSRF über interne
     * Hostnamen (z.B. ein bösartiges OIDC-Discovery-Dokument mit token_endpoint auf
     * http://vault.internal) sowie oktale/dezimale IP-Kodierungen, die filter_var als
     * Hostnamen behandelt. Nicht auflösbare Hosts passieren (der HTTP-Abruf scheitert
     * dann ohnehin harmlos, es gibt kein internes Ziel).
     *
     * Hinweis: schützt nicht gegen DNS-Rebinding (TOCTOU) — dafür wäre ein an cURL
     * gepinnter Resolver nötig; das ist als Follow-up notiert.
     */
    public static function isSafeResolving(?string $url): bool
    {
        if (! self::isSafe($url)) {
            return false;
        }

        $host = self::normalizeHost((string) parse_url((string) $url, PHP_URL_HOST));

        // IP-Literale (inkl. Klammer-IPv6) hat isSafe() bereits geprüft.
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

    /** Ob eine IP-Adresse außerhalb privater/reservierter Bereiche liegt. */
    public static function ipIsPublic(string $ip): bool
    {
        // Defensiv: falls Klammern/Zonen-Index mitkommen.
        $ip = self::normalizeHost($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d, ::ffff:0:0/96) auf die eingebettete
        // IPv4 herunterbrechen und wie IPv4 prüfen — sonst schlüpfen z.B.
        // ::ffff:169.254.169.254 (Cloud-Metadaten) und ::ffff:10.0.0.1 durch.
        $mapped = self::mappedIpv4($ip);
        if ($mapped !== null) {
            $ip = $mapped;
        }

        // Deckt private + reservierte Bereiche für IPv4 UND IPv6 ab.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Explizite IPv6-Ergänzung: ältere PHP-Versionen decken diese Bereiche mit
        // FILTER_FLAG_NO_RES_RANGE nur lückenhaft ab. Belt-and-Suspenders.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($ip);
            if (! is_string($packed) || strlen($packed) !== 16) {
                return false;
            }

            // :: (unspecified) und ::1 (loopback)
            if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15)."\x01") {
                return false;
            }

            $first = ord($packed[0]);

            // fc00::/7 (Unique Local Addresses, u.a. fd00::/8)
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
     * Host für die IP-Prüfung normalisieren: eckige Klammern von IPv6-Literalen
     * (http://[::1]/ → parse_url liefert "[::1]") und einen etwaigen Zonen-Index
     * (fe80::1%eth0) entfernen. Für gewöhnliche Hostnamen/IPv4 ein No-Op.
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
     * Liefert die eingebettete IPv4 einer IPv4-mapped-IPv6-Adresse (::ffff:0:0/96),
     * sonst null.
     */
    private static function mappedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if (! is_string($packed) || strlen($packed) !== 16) {
            return null;
        }

        // Erste 10 Bytes 0x00, danach 0xffff → IPv4-mapped.
        if (substr($packed, 0, 10) === str_repeat("\x00", 10) && substr($packed, 10, 2) === "\xff\xff") {
            $v4 = @inet_ntop(substr($packed, 12, 4));

            return is_string($v4) ? $v4 : null;
        }

        return null;
    }

    /**
     * Alle A- und AAAA-Adressen eines Hostnamens (leer, wenn nicht auflösbar).
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
