<?php

namespace App\Services\Vcs;

use App\Enums\GitProvider;

/**
 * Builds the git environment that authenticates HTTPS access to a private repository
 * using a token (e.g. a GitHub PAT, a GitLab/Bitbucket token). The provider determines
 * the Basic-auth username convention; the token is always the password.
 *
 * The token is passed as an HTTP Authorization header via git's environment-based config
 * (GIT_CONFIG_*), NOT on the command line and NOT baked into the clone URL — so it never
 * lands in `ps` output nor in the mirror's stored `.git/config`.
 *
 * The header is bound to the origin of the repository URL (`http.<origin>.extraHeader`),
 * never configured globally: a global `http.extraHeader` would attach the credential to
 * every host git contacts, so an operator-supplied repository URL pointing at a host they
 * control would receive the stored token in cleartext.
 */
class GitAuth
{
    /**
     * @return array<string, string> environment for Process::env(), empty when no token applies
     */
    public static function env(string $url, ?string $token, ?GitProvider $provider = null, ?string $username = null): array
    {
        $token = $token !== null ? trim($token) : '';

        // Tokens only apply to HTTPS remotes; SSH uses keys.
        if ($token === '' || ! str_starts_with(strtolower($url), 'https://')) {
            return [];
        }

        // Without a resolvable origin the header could not be bound to one — refuse
        // rather than fall back to a global (leaking) configuration.
        $origin = self::origin($url);
        if ($origin === null) {
            return [];
        }

        // Username per provider convention (overridable by a stored credential's username).
        $user = $username !== null && $username !== ''
            ? $username
            : ($provider ?? GitProvider::GitHub)->basicUsername();

        $header = 'Authorization: Basic '.base64_encode($user.':'.$token);

        return [
            'GIT_CONFIG_COUNT' => '2',
            // URL-scoped, so git only attaches the credential to this exact origin.
            'GIT_CONFIG_KEY_0' => 'http.'.$origin.'.extraHeader',
            'GIT_CONFIG_VALUE_0' => $header,
            // A redirect would carry the header to the redirect target, which the origin
            // scoping cannot cover — so authenticated requests never follow redirects.
            'GIT_CONFIG_KEY_1' => 'http.followRedirects',
            'GIT_CONFIG_VALUE_1' => 'false',
            // Never prompt for credentials — fail fast instead of hanging on a bad token.
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    /**
     * The scheme://host[:port] the credential is allowed to reach, normalised the way git
     * matches `http.<url>.*` subsections: lowercase scheme and host, no userinfo, no path.
     * Returns null when the URL has no host to bind to.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $origin = $scheme.'://'.strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * Defensively strip any Authorization header value from an error string before it is
     * surfaced, so a token can never leak through git's stderr.
     */
    public static function scrub(string $message): string
    {
        return (string) preg_replace('/Basic\s+[A-Za-z0-9+\/=]+/', 'Basic <redacted>', $message);
    }
}
