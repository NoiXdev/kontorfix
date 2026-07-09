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

        // IP-Literale in privaten/reservierten Bereichen ablehnen.
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

        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        // IP-Literale hat isSafe() bereits geprüft.
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
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
