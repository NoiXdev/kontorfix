<?php

namespace App\Services\Broadcasting;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Refuses to run the websocket server on a secret that is not a secret.
 *
 * Reverb speaks the Pusher protocol, and that protocol verifies private-channel
 * subscriptions *inside the websocket server*: the client signs
 * `<socket_id>:<channel>` with the app secret and Reverb checks the HMAC itself.
 * `routes/channels.php` — where the operator-channel and per-user authorization
 * actually live — is only consulted by Laravel's `/broadcasting/auth` HTTP endpoint,
 * which a raw `wss://` client never calls. The app secret is therefore the whole
 * access control for broadcasting, and the same secret also authenticates Reverb's
 * HTTP events API (publish into any channel, terminate any connection).
 *
 * This repository shipped a literal one. Anyone who could read the repository could
 * subscribe to `private-operator` on any instance that copied it. Two things follow:
 *
 *  - the published values are refused, not merely discouraged, and
 *  - the refusal happens where the exposure is — at `reverb:start`. A server that
 *    does not start accepts no subscription. Deliberately *not* an application-wide
 *    boot failure: a broadcasting misconfiguration must not take the registry itself
 *    offline, and the operator sees the same problem on the health page.
 *
 * `local` and `testing` are exempt so a developer checkout keeps working; the whole
 * point of the finding is that a *production* instance must not run on a published
 * value.
 */
class ReverbConfigGuard
{
    /**
     * Secret values this repository has published. Anything on this list is known to
     * every reader of the public repository and is treated as no secret at all.
     *
     * @var list<string>
     */
    public const PUBLISHED_SECRETS = [
        'kontorfix-secret',
    ];

    /**
     * Where the websocket container leaves the reason it refused to start, for the app
     * container's health page to read back. The two processes share nothing else: the
     * websocket container has no HTTP surface and mounts no volume, so a refusal that is
     * only written to its own stdout is invisible to anyone not tailing that container.
     *
     * The shipped compose gives every service the same Redis-backed cache. On a store
     * that is not shared (`array`, a per-container `file`) the read simply finds nothing
     * and the health page degrades to the config-derived check below — never worse than
     * before, never a hard dependency.
     */
    private const REFUSAL_KEY = 'broadcasting:reverb-refusal';

    /** How long a recorded refusal stays on the health page without being renewed. */
    private const REFUSAL_TTL = 6 * 3600;

    /**
     * Whether this instance actually broadcasts over Reverb.
     *
     * `docker/.env.example` ships the whole broadcasting block commented out, so the
     * stock instance runs the `null` driver and has no websocket server. A `reverb`
     * container on such an instance relays nothing — it is unauthenticated surface with
     * no purpose — which is why the guard refuses it rather than waving it through.
     */
    public static function broadcastsOverReverb(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }

    /**
     * Describes what is wrong with the configured app secret, or null if it is usable.
     */
    public static function problem(): ?string
    {
        $secret = trim((string) config('reverb.apps.apps.0.secret'));

        if ($secret === '') {
            return 'REVERB_APP_SECRET is empty. The websocket server authorizes every private-channel '
                .'subscription with this value alone; generate one with `openssl rand -hex 32`.';
        }

        if (in_array($secret, self::PUBLISHED_SECRETS, true)) {
            return 'REVERB_APP_SECRET is still the placeholder published in this repository, so it is '
                .'public knowledge and authorizes any anonymous client on every private channel. '
                .'Generate a fresh one with `openssl rand -hex 32` and set it on the app and the '
                .'reverb container alike.';
        }

        return null;
    }

    /**
     * Whether this environment is allowed to run on an unusable secret.
     */
    public static function exempt(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * Aborts the websocket server rather than letting it accept subscriptions that
     * anyone on the internet can sign.
     *
     * Still scoped to `reverb:*` and nothing else: a broadcasting misconfiguration must
     * never take the registry itself offline. What changed is that the reason for a
     * refusal is recorded where the operator can see it, and that an instance which does
     * not broadcast over Reverb is refused for *that* reason instead of being told its
     * secret is wrong.
     */
    public static function assertUsable(): void
    {
        if (self::exempt()) {
            return;
        }

        if (! self::broadcastsOverReverb()) {
            self::refuse(
                'BROADCAST_CONNECTION is not set to `reverb`, so this instance has no websocket '
                .'traffic to relay and the server would only be unauthenticated surface. Either set '
                .'BROADCAST_CONNECTION=reverb (with a secret — see docs/reverb-ops.md) or stop running '
                .'the `reverb` container; it is opt-in via the `reverb` compose profile.'
            );
        }

        $problem = self::problem();

        if ($problem !== null) {
            self::refuse($problem);
        }

        // Came up clean — retract any refusal an earlier boot of this container left on
        // the health page, so a fixed instance stops reporting a problem it no longer has.
        self::remember(fn () => Cache::forget(self::REFUSAL_KEY));
    }

    /**
     * The reason the websocket container last refused to start, or null if it has not
     * refused (recently, or at all).
     */
    public static function recordedRefusal(): ?string
    {
        $value = self::remember(fn () => Cache::get(self::REFUSAL_KEY));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Records the reason for the health page, then aborts the command. */
    private static function refuse(string $problem): never
    {
        self::remember(fn () => Cache::put(self::REFUSAL_KEY, $problem, self::REFUSAL_TTL));

        throw new RuntimeException('Refusing to start Reverb: '.$problem);
    }

    /**
     * Cache access that cannot change the outcome. A refusal must still be a refusal when
     * Redis is down, and the health page must not 500 because the cache is unreachable.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T|null
     */
    private static function remember(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }
}
