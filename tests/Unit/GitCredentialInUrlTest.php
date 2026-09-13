<?php

use App\Services\Vcs\GitAuth;
use App\Services\Vcs\GitRepository;
use App\Support\CredentialUrl;
use Illuminate\Support\Facades\Process;

it('splits userinfo off an https url', function () {
    expect(CredentialUrl::split('https://x-access-token:ghp_secret@github.com/acme/demo.git'))
        ->toBe(['https://github.com/acme/demo.git', 'x-access-token', 'ghp_secret']);
});

it('keeps a url without userinfo untouched', function () {
    expect(CredentialUrl::split('https://github.com/acme/demo.git'))
        ->toBe(['https://github.com/acme/demo.git', null, null]);
});

it('handles a username with no password', function () {
    expect(CredentialUrl::split('https://tokenuser@git.example/acme/demo.git'))
        ->toBe(['https://git.example/acme/demo.git', 'tokenuser', null]);
});

it('leaves ssh urls alone, where the username is part of the transport', function () {
    // `git@` is not a credential — stripping it would simply break the remote. Only the
    // http(s) transports carry a token this way.
    expect(CredentialUrl::split('ssh://git@github.com/acme/demo.git'))
        ->toBe(['ssh://git@github.com/acme/demo.git', null, null])
        ->and(CredentialUrl::split('git@github.com:acme/demo.git'))
        ->toBe(['git@github.com:acme/demo.git', null, null]);
});

it('keeps a percent-encoded password intact', function () {
    // A PAT with reserved characters is percent-encoded in the URL; decoding it is what
    // makes the credential usable as a Basic-auth password.
    expect(CredentialUrl::split('https://user:p%40ss%3Aword@git.example/x.git'))
        ->toBe(['https://git.example/x.git', 'user', 'p@ss:word']);
});

it('preserves port and path when splitting', function () {
    expect(CredentialUrl::split('https://u:t@git.example:8443/a/b.git?x=1'))
        ->toBe(['https://git.example:8443/a/b.git?x=1', 'u', 't']);
});

it('turns an in-url credential into the same auth header the dedicated column produces', function () {
    // The point of the split: a token embedded in the URL must travel the way GitAuth
    // already sends the dedicated `repository_token` — as an origin-scoped extraHeader,
    // never on argv and never in the mirror's stored remote.
    [$url, $username, $token] = CredentialUrl::split('https://x-access-token:ghp_secret@github.com/acme/demo.git');

    $fromUrl = GitAuth::env($url, $token, null, $username);
    $fromColumn = GitAuth::env('https://github.com/acme/demo.git', 'ghp_secret', null, 'x-access-token');

    expect($fromUrl)->toBe($fromColumn)
        ->and(json_encode($fromUrl))->toContain(base64_encode('x-access-token:ghp_secret'));
});

it('never puts an in-url credential on git argv or into the mirror remote', function () {
    // The exposure this closes: `git clone https://user:PAT@host/x.git` publishes the PAT
    // in `ps` for the life of the call, and git then persists the whole URL in the
    // mirror's remote.origin.url — at rest, in plaintext, on the volume. GitRepository's
    // own comment claimed "the mirror's stored URL is token-free"; for this shape it was
    // not, which was confirmed against a real git before this test was written.
    Process::fake();

    (new GitRepository('https://x-access-token:ghp_secret@github.test/acme/demo.git', 'demo-key'))
        ->sync();

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'clone')
            && ! str_contains($command, 'ghp_secret')
            && str_contains($command, 'https://github.test/acme/demo.git');
    });
});

it('still authenticates that clone, with the credential moved into the header', function () {
    Process::fake();

    (new GitRepository('https://x-access-token:ghp_secret@github.test/acme/demo.git', 'demo-key2'))
        ->sync();

    Process::assertRan(fn ($process) => in_array(
        'Authorization: Basic '.base64_encode('x-access-token:ghp_secret'),
        array_values($process->environment),
        true,
    ));
});

it('lets an explicitly stored token win over one embedded in the url', function () {
    Process::fake();

    (new GitRepository(
        'https://x-access-token:from-url@github.test/acme/demo.git',
        'demo-key3',
        token: 'from-column',
    ))->sync();

    Process::assertRan(fn ($process) => in_array(
        'Authorization: Basic '.base64_encode('x-access-token:from-column'),
        array_values($process->environment),
        true,
    ));
});
