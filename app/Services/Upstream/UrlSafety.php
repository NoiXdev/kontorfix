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
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
