<?php

namespace App\Services\Upstream;

/**
 * Anything UpstreamClient can fetch from: a base URL and an optional bearer token.
 *
 * Named `endpointUrl()`/`endpointToken()` rather than `url()`/`token()` because `Upstream`
 * (and `MirrorSource`) already expose `url`/`auth_token` as Eloquent attributes — a method
 * literally named `url()` would shadow the magic accessor instead of extending it.
 */
interface UpstreamEndpoint
{
    public function endpointUrl(): string;

    public function endpointToken(): ?string;
}
