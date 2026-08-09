<?php

namespace App\Services\Vcs;

use App\Enums\GitProvider;

/**
 * Builds the environment for every outbound git invocation: the transport hardening that
 * applies to all of them, plus — when one is supplied — the credential that authenticates
 * HTTPS access to a private repository using a token (e.g. a GitHub PAT, a GitLab/Bitbucket
 * token). The provider determines the Basic-auth username convention; the token is always
 * the password.
 *
 * The hardening is deliberately not conditional on a credential being present. Most git
 * calls this application makes are tokenless public-repository reads, and they are subject
 * to the same address policy (see GitUrlSafety) as the authenticated ones.
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
     * The environment for Process::env() covering EVERY git invocation — the credential is
     * the optional part, the transport hardening is not. Never returns an empty array.
     *
     * @return array<string, string>
     */
    public static function env(string $url, ?string $token, ?GitProvider $provider = null, ?string $username = null): array
    {
        /** @var array<string, string> $config git config keys, in the order git sees them */
        $config = [];

        $header = self::authHeader($url, $token, $provider, $username);
        if ($header !== null) {
            // URL-scoped, so git only attaches the credential to this exact origin.
            $config['http.'.(string) self::origin($url).'.extraHeader'] = $header;
        }

        // Applies with and without a credential. Two reasons, and only the first one is
        // about the token: a redirect would carry the header to the redirect target, which
        // the origin scoping cannot cover; and GitUrlSafety checks the URL once, before git
        // starts, so any hop git chooses on its own is a hop no address policy has seen —
        // arbitrary host, arbitrary port, downgraded to cleartext. The tokenless
        // public-repository call is the normal case and needs this most.
        $config['http.followRedirects'] = 'false';

        $env = ['GIT_CONFIG_COUNT' => (string) count($config)];

        $i = 0;
        foreach ($config as $key => $value) {
            $env["GIT_CONFIG_KEY_{$i}"] = $key;
            $env["GIT_CONFIG_VALUE_{$i}"] = $value;
            $i++;
        }

        // Never prompt for credentials — fail fast instead of hanging on a bad or missing
        // token, which without a TTY would otherwise block the worker.
        $env['GIT_TERMINAL_PROMPT'] = '0';

        return $env;
    }

    /**
     * The `Authorization: Basic …` header value for the remote, or null when no credential
     * applies (no token, a non-HTTPS transport, or no origin to bind the header to).
     */
    private static function authHeader(string $url, ?string $token, ?GitProvider $provider, ?string $username): ?string
    {
        $token = $token !== null ? trim($token) : '';

        // Tokens only apply to HTTPS remotes; SSH uses keys.
        if ($token === '' || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        // Without a resolvable origin the header could not be bound to one — refuse
        // rather than fall back to a global (leaking) configuration.
        if (self::origin($url) === null) {
            return null;
        }

        // Username per provider convention (overridable by a stored credential's username).
        $user = $username !== null && $username !== ''
            ? $username
            : ($provider ?? GitProvider::GitHub)->basicUsername();

        return 'Authorization: Basic '.base64_encode($user.':'.$token);
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
