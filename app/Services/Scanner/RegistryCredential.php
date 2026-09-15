<?php

namespace App\Services\Scanner;

/**
 * Where the adapter fetches the artifact, and what it presents to do so.
 *
 * `$url` is the address the adapter can reach US on, which is not APP_URL: that is the
 * public address, and from inside the compose network the registry is `app:8080`. See
 * `kontorfix.scanner.registry_url`.
 */
final class RegistryCredential
{
    public function __construct(
        public readonly string $url,
        public readonly string $bearer,
    ) {}
}
