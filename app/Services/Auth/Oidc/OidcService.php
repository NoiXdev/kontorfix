<?php

namespace App\Services\Auth\Oidc;

use App\Models\OidcProvider;

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
}
