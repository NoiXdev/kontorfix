<?php

namespace App\Services\Auth\Oidc;

use App\Models\OidcProvider;
use App\Services\Upstream\UrlSafety;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OidcService
{
    public function authorizationUrl(OidcProvider $provider, string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => $provider->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $provider->authorization_endpoint.'?'.$query;
    }

    /**
     * @return array{id_token:string,access_token:?string}
     */
    public function exchangeCode(OidcProvider $provider, string $code, string $codeVerifier, string $redirectUri): array
    {
        if (! UrlSafety::isSafe($provider->token_endpoint)) {
            throw new RuntimeException('Unsicherer token_endpoint.');
        }

        $response = Http::asForm()->timeout(10)->withoutRedirecting()->acceptJson()->post($provider->token_endpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'code_verifier' => $codeVerifier,
        ]);

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            throw new RuntimeException('Token-Tausch fehlgeschlagen.');
        }

        $access = $response->json('access_token');

        return ['id_token' => $response->json('id_token'), 'access_token' => is_string($access) ? $access : null];
    }

    /**
     * Verifiziert Signatur (JWKS/RS256) und Claims (iss, aud, exp via JWT::decode; nonce).
     *
     * @return array<string,mixed>
     */
    public function verifyIdToken(OidcProvider $provider, string $idToken, string $nonce): array
    {
        if (! UrlSafety::isSafe($provider->jwks_uri)) {
            throw new RuntimeException('Unsichere jwks_uri.');
        }

        $jwks = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($provider->jwks_uri)->json();
        if (! is_array($jwks) || ! isset($jwks['keys'])) {
            throw new RuntimeException('JWKS konnte nicht geladen werden.');
        }

        try {
            $keys = JWK::parseKeySet($jwks);
            $payload = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new RuntimeException('id_token-Signatur ungültig: '.$e->getMessage());
        }

        if (($payload['iss'] ?? null) !== $provider->issuer) {
            throw new RuntimeException('iss stimmt nicht mit dem Provider überein.');
        }

        $aud = $payload['aud'] ?? null;
        $audOk = is_array($aud) ? in_array($provider->client_id, $aud, true) : $aud === $provider->client_id;
        if (! $audOk) {
            throw new RuntimeException('aud stimmt nicht mit der client_id überein.');
        }

        if (! hash_equals($nonce, (string) ($payload['nonce'] ?? ''))) {
            throw new RuntimeException('nonce stimmt nicht — möglicher Replay.');
        }

        return $payload;
    }
}
