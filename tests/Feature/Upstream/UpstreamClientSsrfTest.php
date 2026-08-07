<?php

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use App\Services\Upstream\UpstreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// I1: getJson() must — like getBytes() — follow redirects manually and check each hop
// against the SSRF rules. A malicious upstream must not be able to redirect a metadata
// fetch via 302 to an internal address (127.0.0.1 / [::1]).
it('refuses a metadata redirect to an internal ipv4 address', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data/']),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    expect(fn () => app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json'))
        ->toThrow(UpstreamException::class);

    // The internal target must never be requested.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
});

it('refuses a metadata redirect to an internal ipv6 loopback', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'http://[::1]/latest/meta-data/']),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    expect(fn () => app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json'))
        ->toThrow(UpstreamException::class);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '[::1]') || str_contains($r->url(), '::1'));
});

it('follows a safe metadata redirect to another public host', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'https://mirror.test/p2/acme/demo.json']),
        'mirror.test/*' => Http::response(['ok' => true], 200),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    expect(app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json'))->toBe(['ok' => true]);
});

// I2: getBytes() re-sets the bearer token on every hop — even after a redirect to
// a foreign host. A malicious upstream could otherwise capture the private upstream
// token this way. The token must only go to the original upstream host; after
// a host change NO Authorization header may be sent anymore.
it('drops the upstream auth token on a cross-host artifact redirect', function () {
    Http::fake([
        'cdn.test/a/*' => Http::response('', 302, ['Location' => 'https://evil-collector.example/loot.zip']),
        'evil-collector.example/*' => Http::response('zip-bytes', 200),
    ]);
    // Upstream host == host of the artifact URL: the first hop legitimately carries the token.
    $up = Upstream::factory()->create(['url' => 'https://cdn.test', 'auth_token' => 'secret-token']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');

    // The foreign host must not see the private bearer token.
    Http::assertSent(fn ($r) => $r->url() === 'https://evil-collector.example/loot.zip' && ! $r->hasHeader('Authorization'));
    // The original upstream host still gets the token.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'cdn.test/a/b.zip') && $r->hasHeader('Authorization', 'Bearer secret-token'));
});

it('keeps the upstream auth token on a same-host artifact redirect', function () {
    Http::fake([
        'cdn.test/a/*' => Http::response('', 302, ['Location' => 'https://cdn.test/final/b.zip']),
        'cdn.test/final/*' => Http::response('zip-bytes', 200),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://cdn.test', 'auth_token' => 'secret-token']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'cdn.test/final/b.zip') && $r->hasHeader('Authorization', 'Bearer secret-token'));
});

// A02: the mirror credential must never cross the network in cleartext. GitAuth already
// refuses to attach a git token to a non-HTTPS remote; the upstream client did not.
it('does not send the upstream auth token over cleartext http', function () {
    Http::fake([
        'repo.test/*' => Http::response(['ok' => true], 200),
    ]);
    $up = Upstream::factory()->create(['url' => 'http://repo.test', 'auth_token' => 'mirror-secret']);

    app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'repo.test') && ! $r->hasHeader('Authorization'));
});

it('does not send the upstream auth token on an http hop of an https upstream', function () {
    Http::fake([
        'cdn.test/a/*' => Http::response('', 302, ['Location' => 'http://cdn.test/final/b.zip']),
        'cdn.test/final/*' => Http::response('zip-bytes', 200),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://cdn.test', 'auth_token' => 'mirror-secret']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');

    Http::assertSent(fn ($r) => $r->url() === 'http://cdn.test/final/b.zip' && ! $r->hasHeader('Authorization'));
});

it('still sends the token to an https upstream', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'mirror-secret']);

    app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer mirror-secret'));
});
