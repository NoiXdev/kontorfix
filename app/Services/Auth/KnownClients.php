<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Has this browser ever completed an authentication for this account?"
 *
 * This is the only signal available *before* a password comparison that the account
 * holder possesses and an anonymous attacker does not, and that is the entire reason it
 * exists. Every bound on login guessing runs into the same wall: to refuse a guess you
 * must decide before you know whether the submitted password is correct, and a refusal
 * taken on the account's own counter is therefore an anonymous, targeted lockout. A
 * marker the holder's own browser carries breaks that tie — the throttle can refuse the
 * traffic it cannot recognise while the holder walks straight through.
 *
 * Deliberately weak by construction, because it is not an authentication factor:
 *
 *  - it proves nothing on its own and grants nothing on its own. All it ever buys is a
 *    place in the queue that an unrecognised client is refused. Forging it (which needs
 *    APP_KEY, since the `web` group encrypts cookies) would restore the *previous*
 *    release's posture, not create a new capability.
 *  - it stores no addresses and no identifiers, only a keyed digest per account, so a
 *    shared machine's cookie does not enumerate who has logged in there even if the
 *    encryption is broken.
 *  - it is never cleared on logout. It records that this browser has been here, not that
 *    anybody is signed in; clearing it would hand an attacker a way to strip the holder's
 *    recognition by triggering a logout.
 *
 * An account holder who has no marker — new machine, cleared cookies — is not stranded:
 * completing a password reset marks the browser too (NewPasswordController), and that
 * path is deliberately throttled per source address only, so it cannot be denied to them
 * by anybody flooding their account.
 */
class KnownClients
{
    public const COOKIE = 'known_clients';

    /** Accounts remembered per browser. Enough for a shared machine, small enough to stay a cookie. */
    private const MAX_ENTRIES = 5;

    private const LIFETIME_MINUTES = 60 * 24 * 365;

    /**
     * Whether this browser has previously authenticated as the given address.
     *
     * Takes the address rather than a User because the login throttle has to answer this
     * question before it resolves — or fails to resolve — an account, and because the
     * answer must be identical for an address that has no account at all.
     */
    public function recognises(Request $request, ?string $email): bool
    {
        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        return in_array($this->fingerprint($email), $this->entries($request), true);
    }

    /** Queue the marker for this account onto the outgoing response. */
    public function remember(Request $request, User $user): void
    {
        $email = $user->email;

        // Robots hold no mailbox and never reach a browser session anyway
        // (RejectRobotWebSession); nothing to mark, and no empty key to collide on.
        if (blank($email)) {
            return;
        }

        $fingerprint = $this->fingerprint((string) $email);

        $entries = array_values(array_filter(
            $this->entries($request),
            fn (string $entry): bool => $entry !== $fingerprint,
        ));

        array_unshift($entries, $fingerprint);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: (string) json_encode(array_slice($entries, 0, self::MAX_ENTRIES)),
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * Keyed digest of the address.
     *
     * HMAC rather than a bare hash so the value is not precomputable from a guessed
     * address list if the cookie ever escapes its encryption, and so a marker minted by
     * one instance means nothing to another.
     */
    public function fingerprint(string $email): string
    {
        return substr(hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key')), 0, 32);
    }

    /**
     * @return array<int, string>
     */
    private function entries(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            fn (mixed $entry): bool => is_string($entry) && preg_match('/^[0-9a-f]{32}$/', $entry) === 1,
        ));
    }
}
