<?php

namespace App\Services\Auth\Oidc;

use App\Services\Upstream\UrlSafety;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OidcDiscovery
{
    /**
     * @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:?string,jwks_uri:string}
     */
    public function discover(string $issuer): array
    {
        if (! self::isHttps($issuer) || ! UrlSafety::isSafeResolving($issuer)) {
            throw new RuntimeException('Unsichere issuer-URL.');
        }

        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

        $response = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Discovery fehlgeschlagen (HTTP {$response->status()}).");
        }

        $doc = $response->json();
        if (! is_array($doc)) {
            throw new RuntimeException('Discovery-Dokument ist kein gültiges JSON-Objekt.');
        }

        $endpoints = [
            'issuer' => (string) ($doc['issuer'] ?? ''),
            'authorization_endpoint' => (string) ($doc['authorization_endpoint'] ?? ''),
            'token_endpoint' => (string) ($doc['token_endpoint'] ?? ''),
            'userinfo_endpoint' => isset($doc['userinfo_endpoint']) ? (string) $doc['userinfo_endpoint'] : null,
            'jwks_uri' => (string) ($doc['jwks_uri'] ?? ''),
        ];

        // The document is served by the IdP, so its contents are exactly as trustworthy as
        // the IdP. An https issuer advertising an http token endpoint would downgrade the
        // client_secret's transport on the strength of a claim, which is why the scheme is
        // re-checked here and not only on the issuer.
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! self::isHttps($endpoints[$key]) || ! UrlSafety::isSafeResolving($endpoints[$key])) {
                throw new RuntimeException("Unsicherer/fehlender Endpunkt: {$key}.");
            }
        }
        if ($endpoints['userinfo_endpoint'] !== null
            && (! self::isHttps($endpoints['userinfo_endpoint']) || ! UrlSafety::isSafeResolving($endpoints['userinfo_endpoint']))) {
            throw new RuntimeException('Unsicherer userinfo_endpoint.');
        }

        return $endpoints;
    }

    /**
     * Checked separately from UrlSafety, which answers a different question: UrlSafety is
     * the outbound ADDRESS policy shared by every sink in the application, and it allows
     * http because artifact fetches legitimately need it. TLS is a requirement of this
     * protocol specifically, so it belongs here rather than as a widening of that policy.
     */
    private static function isHttps(?string $url): bool
    {
        return $url !== null && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
