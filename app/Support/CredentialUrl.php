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
     * Separates an http(s) URL's userinfo from the URL itself.
     *
     * redact() withholds a credential from a READER. This removes it from the value that
     * gets handed to a subprocess, which is a different exposure: `git clone` with the
     * credential still in the URL puts it on argv — readable in `ps` for the life of the
     * call — and git then writes the whole URL into the mirror's `remote.origin.url`,
     * where it stays at rest in plaintext. Splitting lets the caller pass the credential
     * the way GitAuth already carries the dedicated `repository_token`: as an
     * origin-scoped `http.<origin>.extraHeader`, which reaches neither.
     *
     * Only http and https are split. `git@github.com:acme/x.git` and
     * `ssh://git@host/x.git` put a transport username in the same position, and it is not
     * a secret — removing it would just break the remote.
     *
     * The password is percent-decoded, because a PAT containing reserved characters is
     * encoded in the URL and has to be decoded to be usable as a Basic-auth password.
     *
     * @return array{0: string, 1: string|null, 2: string|null} [url without userinfo, username, password]
     */
    public static function split(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return [$url, null, null];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['user'])) {
            return [$url, null, null];
        }

        $user = rawurldecode((string) $parts['user']);
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;

        $rebuilt = $scheme.'://'.strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= (string) ($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return [$rebuilt, $user === '' ? null : $user, $pass === '' ? null : $pass];
    }

    /**
     * Whether the value carries a userinfo component at all — redacted or not.
     */
    public static function carries(?string $url): bool
    {
        return $url !== null && preg_match(self::USERINFO, $url) === 1;
    }

    /**
     * The URL with any userinfo component removed outright, marker and all.
     *
     * Distinct from redact(), and the distinction matters wherever a URL is *used* rather
     * than *displayed*. A `Location` header is handed to a client that will dial it:
     *
     * `https://***@host/` is a credential the client would actually try to authenticate
     * with, and the marker still announces that a credential exists and on which host.
     * Redaction is the right tool for a value a human reads; removal is the right tool
     * for a value a machine follows.
     *
     * Removing userinfo does not make a credentialled upstream safe to redirect to — the
     * request simply arrives unauthenticated. Callers must decide whether an anonymous
     * request to that host is acceptable at all; see PypiController::simpleProject().
     */
    public static function strip(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return (string) preg_replace(self::USERINFO, '${1}', $url);
    }

    /**
     * Whether the value carries the marker in place of real userinfo — i.e. it is a
     * redacted value on its way back in. Write paths refuse it rather than storing it,
     * so a client that echoes a withheld value can never silently destroy the credential
     * it was withheld from.
     */
    public static function isRedacted(?string $url): bool
    {
        return self::carries($url) && self::redact($url) === $url;
    }
}
