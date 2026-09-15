<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The record a rejected token leaves.
 *
 * Guessing either credential is hopeless — 238 bits behind a sha256 lookup — so this is not
 * about catching a break-in. It is about an operator being able to see that something is
 * scanning, or that a deployed client is hammering a credential somebody revoked. Neither
 * was visible before: a lookup that found nothing simply returned null.
 *
 * Two rules, both load-bearing:
 *
 *  - **Never the credential.** The presented value is a secret whether or not it resolved —
 *    a typo in a deployment config is one character away from a live token, and a log that
 *    captured rejects would collect those. Only the source address and the path are kept.
 *  - **Deduplicated.** The registry protocol routes carry no request budget by design (a
 *    cold `composer install` fires hundreds), so a line per rejected request would be a
 *    log-amplification primitive reachable by anyone. One line per (kind, address, path)
 *    per minute is enough to see the pattern and cannot be used to fill a disk — the same
 *    window, and the same reasoning, as LogAuthenticationEvent's lockout dedupe.
 *
 * Shared by both middlewares rather than restated in each: one rule, one place, and the two
 * cannot drift into disagreeing about what is safe to write down.
 */
final class RejectedCredentialLog
{
    private const QUIET_SECONDS = 60;

    public static function record(string $message, Request $request): void
    {
        $ip = (string) $request->ip();
        $path = $request->path();

        try {
            if (! Cache::add('rejected-credential|'.sha1($message.'|'.$ip.'|'.$path), true, self::QUIET_SECONDS)) {
                return;
            }
        } catch (Throwable $e) {
            // A cache outage must not silence the record AND must not break the request the
            // caller is in the middle of refusing. Log it undeduplicated and say why, rather
            // than swallowing the outage along with the signal.
            Log::warning('Could not deduplicate a rejected-credential log entry.', ['exception' => $e]);
        }

        Log::warning($message, ['ip' => $ip, 'path' => $path]);
    }
}
