<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A one-time-ish secret that gates the first-run wizard.
 *
 * On a fresh deployment the wizard creates the first admin without authentication, so
 * whoever reaches the URL first would own the instance. Requiring a token that is only
 * printed to the container's startup logs means only someone with server/log access can
 * complete setup. The token is regenerated on every app start (the entrypoint calls
 * `setup:token`), so a leaked value from a previous boot is useless.
 *
 * When no token is stored (e.g. local dev where the entrypoint never ran), the gate is
 * simply absent and the wizard is open — the token is an added production safeguard,
 * not a hard requirement of the flow.
 */
class SetupToken
{
    private const KEY = 'setup.token';

    public function current(): ?string
    {
        $value = Cache::get(self::KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function regenerate(): string
    {
        $token = Str::random(40);
        // No TTL: it lives until the next boot regenerates it or setup clears it.
        Cache::forever(self::KEY, $token);

        return $token;
    }

    public function clear(): void
    {
        Cache::forget(self::KEY);
    }

    public function matches(?string $candidate): bool
    {
        $current = $this->current();

        return $current !== null && is_string($candidate) && $candidate !== '' && hash_equals($current, $candidate);
    }
}
