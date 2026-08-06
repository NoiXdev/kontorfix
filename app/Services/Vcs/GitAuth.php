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

        // Username per provider convention (overridable by a stored credential's username).
        $user = $username !== null && $username !== ''
            ? $username
            : ($provider ?? GitProvider::GitHub)->basicUsername();

        $header = 'Authorization: Basic '.base64_encode($user.':'.$token);

        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => $header,
            // Never prompt for credentials — fail fast instead of hanging on a bad token.
            'GIT_TERMINAL_PROMPT' => '0',
        ];
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
