<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only record a login attack leaves.
 *
 * `Failed` and `Lockout` were dispatched into a void — no listener, nothing written — which
 * made the login throttle's own docblock false where it named monitoring as the
 * compensating control for everything it deliberately does not refuse. Two counters on
 * that endpoint may not bound an attacker with unlimited source addresses and unlimited
 * concurrency without either denying the account holder or parking workers, so what is
 * left un-refused is genuinely left to the operator and to whatever sits in front of the
 * application. That is only an argument if the operator can see it.
 *
 * Two rules, both load-bearing:
 *
 *  - **never the credentials.** `Failed::$credentials` carries the submitted password when
 *    the framework's own `Auth::attempt()` raises it. A listener that logged the array
 *    would turn a brute-force attempt into a plaintext password file — including the
 *    victim's real password on the attempt that finally lands. Only the addressee is read
 *    out, and it is truncated, because it is attacker-controlled text.
 *  - **Lockout is deduplicated.** It fires on every refused request once a counter is
 *    spent, and the refusal is reachable anonymously, so writing a line per event would
 *    hand an anonymous caller a log-amplification primitive. One line per (address, target)
 *    per minute is enough to see an attack and cannot be used to fill a disk.
 *
 * `Failed` is not deduplicated: it is raised once per *compared* password, and the
 * comparison is what the throttles already bound.
 */
class LogAuthenticationEvent
{
    private const LOCKOUT_QUIET_SECONDS = 60;

    public function onFailed(Failed $event): void
    {
        Log::warning('Authentication failed.', [
            'guard' => $event->guard,
            'user_id' => $event->user?->getAuthIdentifier(),
            // Never $event->credentials: it holds the submitted password.
            'email' => $this->scrub($event->credentials['email'] ?? null),
            'ip' => request()->ip(),
            'path' => request()->path(),
        ]);
    }

    public function onLockout(Lockout $event): void
    {
        $email = $this->scrub($event->request->input('email'));
        $ip = $event->request->ip();

        if (! Cache::add('auth-lockout-logged|'.sha1($ip.'|'.$email), true, self::LOCKOUT_QUIET_SECONDS)) {
            return;
        }

        Log::warning('Authentication throttled.', [
            'email' => $email,
            'ip' => $ip,
            'path' => $event->request->path(),
        ]);
    }

    /** Attacker-controlled text on its way into a log line. */
    private function scrub(mixed $value): ?string
    {
        return is_string($value) ? Str::limit(Str::of($value)->replaceMatches('/[[:cntrl:]]/', '')->toString(), 190) : null;
    }
}
