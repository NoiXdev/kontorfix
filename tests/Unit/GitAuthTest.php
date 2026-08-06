<?php

use App\Services\Vcs\GitAuth;

it('builds an authorization header env for an https url with a token', function () {
    $env = GitAuth::env('https://github.com/acme/private.git', 'ghp_secret');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.extraHeader')
        ->and($env['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-access-token:ghp_secret'))
        ->and($env['GIT_TERMINAL_PROMPT'])->toBe('0');
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
