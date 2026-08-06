<?php

use App\Enums\GitProvider;
use App\Services\Vcs\GitAuth;

it('builds an authorization header env for an https url with a token (github default)', function () {
    $env = GitAuth::env('https://github.com/acme/private.git', 'ghp_secret');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.extraHeader')
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

it('scrubs a basic auth header out of an error message', function () {
    $token = base64_encode('x-access-token:supersecret');
    $scrubbed = GitAuth::scrub("fatal: unable to access, header Basic {$token} rejected");

    expect($scrubbed)->toContain('Basic <redacted>')
        ->and($scrubbed)->not->toContain($token);
});
