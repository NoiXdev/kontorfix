<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A one-time-ish secret that gates the first-run wizard.
 *
 * On a fresh deployment the wizard creates the first admin without authentication, so
 * whoever reaches the URL first would own the instance. Requiring a token that is only
 * printed to the container's startup logs means only someone with server/log access can
 * complete setup. The token is regenerated on every app start (the entrypoint calls
 * `setup:token`), so a leaked value from a previous boot is useless.
 *
 * "No token stored" is deliberately NOT the same as "no gate": whether a token is
 * demanded is decided by SetupGate from configuration, and a token that cannot be read
 * locks the wizard rather than opening it. This class only answers "what is stored".
 */
class SetupToken
{
    private const KEY = 'setup.token';

    /**
     * The stored token, or null when there is none — including when the store cannot be
     * answered at all. Reading must never raise: every caller is a gate, and an
     * exception escaping here would turn "cannot tell" into a 500 today and, the moment
     * somebody catches it, into an open wizard. Callers treat null as "locked".
     */
    public function current(): ?string
    {
        try {
            $value = Cache::get(self::KEY);
        } catch (Throwable $e) {
            Log::warning('Setup token store unreadable; treating the setup gate as locked.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

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
