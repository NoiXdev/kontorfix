<?php

namespace App\Services\Http;

use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The `Host` allowlist for `Illuminate\Http\Middleware\TrustHosts`.
 *
 * Without one, `Request::getTrustedHosts()` is empty and `getSchemeAndHttpHost()`
 * returns whatever the client sent, so a proxy with a catch-all router (the natural
 * shape once custom registry domains are in use) lets an attacker choose the host in
 * every generated URL — including the password-reset link delivered to the victim.
 *
 * The list is assembled from the three sources that legitimately name this instance:
 *
 *  - the `APP_URL` host and its subdomains (the shape Laravel itself defaults to),
 *  - the loopback names, because the container healthcheck curls
 *    `http://localhost:8080/up` and the documented host-local deployment has no proxy
 *    rewriting the host at all — refusing these would restart-loop the container,
 *  - every hostname an operator attached to a registry group, since those reach the
 *    same application at the host root.
 *
 * It fails **open** in exactly one case: when `APP_URL` names no host there is nothing
 * to build an allowlist from, and an empty array means "trust everything" rather than
 * "trust nothing". Locking an instance out of itself over an unset variable would be a
 * worse bug than the one this closes, and it is not the only control — the URL root is
 * pinned to `APP_URL` in AppServiceProvider regardless of what any header says.
 */
class TrustedHosts
{
    public const CACHE_KEY = 'http.trusted-hosts';

    /**
     * How long the attached-hostname lookup is reused. Domain writes clear the key
     * (see Domain::booted), so this only bounds the staleness of a change made
     * outside the application.
     */
    private const CACHE_TTL = 60;

    /**
     * Host patterns in the form Symfony matches them (`{<pattern>}i`).
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($appHost) || $appHost === '') {
            return [];
        }

        $patterns = ['^(.+\.)?'.preg_quote($appHost).'$'];

        foreach (['localhost', '127.0.0.1', '::1', '[::1]', ...self::attachedHostnames()] as $host) {
            $patterns[] = '^'.preg_quote($host).'$';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Whether a hostname is one an operator attached to a registry group.
     *
     * Shares the cache the allowlist is built from, so asking this per request costs no
     * additional query. Case-insensitive, because `Request::getHost()` lowercases but a
     * caller elsewhere may not.
     */
    public static function isAttachedHostname(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host !== '' && in_array($host, self::attachedHostnames(), true);
    }

    /**
     * Hostnames the operator attached to a registry group.
     *
     * Swallows store failures on purpose: this runs in front of every request, so a
     * database or cache blip must not turn into a 500 on the healthcheck. The fallback
     * keeps the APP_URL host and loopback trusted, which is the set that has to work
     * for the instance to come back at all — a custom-domain request is refused during
     * the outage, and it could not have been resolved to a group either way.
     *
     * @return list<string>
     */
    private static function attachedHostnames(): array
    {
        try {
            /** @var list<string> $hostnames */
            $hostnames = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                fn (): array => Domain::query()->pluck('hostname')->all(),
            );

            return $hostnames;
        } catch (Throwable) {
            return [];
        }
    }
}
