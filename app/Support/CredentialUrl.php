<?php

namespace App\Support;

/**
 * Userinfo redaction for the two URL columns operators legitimately put secrets into.
 *
 * `upstreams.url` is the only way to reach a Basic-auth mirror — UpstreamClient applies
 * the dedicated, encrypted `auth_token` as a Bearer header and nothing else — and
 * `packages.repository_url` carries a git PAT whenever an admin skips the dedicated
 * `repository_token`. Neither column can simply reject userinfo: doing so would break
 * every mirror and mirror-sync that works today, with no supported alternative to move
 * the credential to. So the value is kept and withheld from readers below the tier that
 * wrote it.
 */
final class CredentialUrl
{
    /** What a withheld userinfo component is replaced by. */
    public const MARKER = '***';

    /**
     * Matches a URL's userinfo component and nothing else.
     *
     * `[^/\s]*` cannot cross into the path, so an `@` in a path segment (a Composer
     * `name@version` dist filename, for instance) is never mistaken for a credential.
     * It is greedy, so the LAST `@` of the authority wins and a password that itself
     * contains an `@` is removed whole rather than half-kept.
     */
    private const USERINFO = '~^([a-zA-Z][a-zA-Z0-9+.\-]*://)[^/\s]*@~';

    /**
     * Replaces the userinfo component, if any, with the marker. A URL without one — and
     * a value that is not a URL at all — is returned unchanged, so this is safe to apply
     * unconditionally on a read path.
     *
     * Note that this also redacts a non-secret conventional username such as the `git@`
     * of an ssh remote. The application cannot tell `git@` from `x-access-token@`, and
     * withholding a username from a member-tier reader costs nothing.
     */
    public static function redact(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return (string) preg_replace(self::USERINFO, '${1}'.self::MARKER.'@', $url);
    }

    /**
     * Whether the value carries the marker in place of real userinfo — i.e. it is a
     * redacted value on its way back in. Write paths refuse it rather than storing it,
     * so a client that echoes a withheld value can never silently destroy the credential
     * it was withheld from.
     */
    public static function isRedacted(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        return preg_match(self::USERINFO, $url) === 1
            && self::redact($url) === $url;
    }
}
