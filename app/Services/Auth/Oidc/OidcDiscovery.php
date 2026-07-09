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
        if (! UrlSafety::isSafeResolving($issuer)) {
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

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! UrlSafety::isSafeResolving($endpoints[$key])) {
                throw new RuntimeException("Unsicherer/fehlender Endpunkt: {$key}.");
            }
        }
        if ($endpoints['userinfo_endpoint'] !== null && ! UrlSafety::isSafeResolving($endpoints['userinfo_endpoint'])) {
            throw new RuntimeException('Unsicherer userinfo_endpoint.');
        }

        return $endpoints;
    }
}
