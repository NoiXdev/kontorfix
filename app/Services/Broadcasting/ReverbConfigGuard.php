<?php

namespace App\Services\Broadcasting;

use RuntimeException;

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
     */
    public static function assertUsable(): void
    {
        if (self::exempt()) {
            return;
        }

        $problem = self::problem();

        if ($problem !== null) {
            throw new RuntimeException('Refusing to start Reverb: '.$problem);
        }
    }
}
