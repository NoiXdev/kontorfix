<?php

use App\Enums\GitProvider;
use App\Services\Vcs\GitAuth;

it('builds an authorization header env for an https url with a token (github default)', function () {
    $env = GitAuth::env('https://github.com/acme/private.git', 'ghp_secret');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://github.com.extraHeader')
        ->and($env['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-access-token:ghp_secret'))
        ->and($env['GIT_TERMINAL_PROMPT'])->toBe('0');
});

it('uses the provider-specific basic username', function () {
    $gitlab = GitAuth::env('https://gitlab.com/acme/private.git', 'glpat-x', GitProvider::GitLab);
    $bitbucket = GitAuth::env('https://bitbucket.org/acme/private.git', 'bb-x', GitProvider::Bitbucket);

    expect($gitlab['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('oauth2:glpat-x'))
        ->and($bitbucket['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-token-auth:bb-x'));
});

it('honours an explicit username override', function () {
    $env = GitAuth::env('https://git.example.test/acme/private.git', 'tok', GitProvider::Generic, 'deploy-bot');

    expect($env['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('deploy-bot:tok'));
});

it('does not build auth for ssh urls or when no token is given', function () {
    expect(GitAuth::env('ssh://git@github.com/acme/private.git', 'ghp_secret'))->toBe([])
        ->and(GitAuth::env('https://github.com/acme/public.git', null))->toBe([])
        ->and(GitAuth::env('https://github.com/acme/public.git', '   '))->toBe([]);
});

it('binds the authorization header to the remote origin instead of applying it globally', function () {
    $env = GitAuth::env('https://github.com/acme/private.git', 'ghp_secret');

    // The global `http.extraHeader` form would send the token to ANY host the
    // command happens to contact — including one an operator supplied.
    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://github.com.extraHeader');
});

it('scopes the header to the exact host and port of the remote', function () {
    $env = GitAuth::env('https://git.example.test:8443/acme/private.git', 'tok');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://git.example.test:8443.extraHeader');
});

it('normalises the origin, dropping userinfo, path and host casing', function () {
    $env = GitAuth::env('https://Bot@GitHub.COM/Acme/Private.git?x=1', 'tok');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://github.com.extraHeader');
});

it('does not follow redirects, so the header cannot be replayed to another host', function () {
    $env = GitAuth::env('https://github.com/acme/private.git', 'ghp_secret');

    $config = [];
    for ($i = 0; $i < (int) $env['GIT_CONFIG_COUNT']; $i++) {
        $config[$env["GIT_CONFIG_KEY_{$i}"]] = $env["GIT_CONFIG_VALUE_{$i}"];
    }

    expect($config)->toHaveKey('http.followRedirects')
        ->and($config['http.followRedirects'])->toBe('false');
});

it('builds no auth when the url has no resolvable host', function () {
    expect(GitAuth::env('https:///acme/private.git', 'ghp_secret'))->toBe([]);
});

it('scrubs a basic auth header out of an error message', function () {
    $token = base64_encode('x-access-token:supersecret');
    $scrubbed = GitAuth::scrub("fatal: unable to access, header Basic {$token} rejected");

    expect($scrubbed)->toContain('Basic <redacted>')
        ->and($scrubbed)->not->toContain($token);
});
