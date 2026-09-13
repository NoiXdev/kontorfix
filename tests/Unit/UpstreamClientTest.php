<?php

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use App\Services\Upstream\UpstreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('fetches upstream json and sends bearer auth when configured', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    $data = app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json');

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->url(), 'repo.test/p2/acme/demo.json'));
});

it('sends no auth header when no token configured', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    app(UpstreamClient::class)->getJson($up, '/p2/x/y.json');
    Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
});

it('returns null on upstream 404', function () {
    Http::fake(['repo.test/*' => Http::response('', 404)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    expect(app(UpstreamClient::class)->getJson($up, '/p2/x/y.json'))->toBeNull();
});

it('throws UpstreamException on a 500, carrying the status as a language-neutral fact', function () {
    Http::fake(['repo.test/*' => Http::response('boom', 500)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    $thrown = fn () => app(UpstreamClient::class)->getJson($up, '/p2/x/y.json');
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (UpstreamException $e) {
        // status() is what a caller (e.g. MirrorSyncFailed) should read instead of
        // getMessage(), which is English prose not meant to be shown to an operator.
        expect($e->status())->toBe(500);
    });
});

it('leaves status() null for a transport-level refusal that never got an upstream response', function () {
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);
    Http::fake(['repo.test/*' => Http::response('', 302, ['Location' => ''])]);

    $thrown = fn () => app(UpstreamClient::class)->getJson($up, '/p2/x/y.json');
    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (UpstreamException $e) {
        expect($e->status())->toBeNull();
    });
});

it('fetches raw bytes for an artifact url', function () {
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');
});

it('pins the connection to the address the safety check validated', function () {
    // The gap this closes: isSafeResolving() resolves the host, approves it, and then
    // libcurl resolves the SAME name a second time to decide where to connect. An
    // authoritative zone under the attacker's control can answer differently the second
    // time (DNS rebinding), so the address that was judged is not the address that is
    // dialled. Pinning hands libcurl the judged address instead of a name to re-resolve.
    $this->resolveHostTo('repo.test', ['203.0.113.10']);

    $captured = null;
    Http::fake(function ($request, $options) use (&$captured) {
        $captured = $options;

        return Http::response(['ok' => true], 200);
    });

    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);
    app(UpstreamClient::class)->getJson($up, '/p2/x/y.json');

    expect($captured['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['repo.test:443:203.0.113.10']);
});

it('pins every address the host resolved to, so a multi-homed upstream keeps its failover', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10', '198.51.100.7']);

    $captured = null;
    Http::fake(function ($request, $options) use (&$captured) {
        $captured = $options;

        return Http::response(['ok' => true], 200);
    });

    $up = Upstream::factory()->create(['url' => 'http://repo.test:8080', 'auth_token' => null]);
    app(UpstreamClient::class)->getJson($up, '/p2/x/y.json');

    expect($captured['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['repo.test:8080:203.0.113.10,198.51.100.7']);
});

it('re-pins on a redirect hop instead of carrying the first hop\'s address', function () {
    $this->resolveHostTo('repo.test', ['203.0.113.10']);
    $this->resolveHostTo('cdn.example', ['198.51.100.7']);

    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return count($seen) === 1
            ? Http::response('', 302, ['Location' => 'https://cdn.example/dist.zip'])
            : Http::response('bytes', 200);
    });

    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);
    app(UpstreamClient::class)->getBytes($up, 'https://repo.test/dist.zip');

    expect($seen)->toBe([
        ['repo.test:443:203.0.113.10'],
        ['cdn.example:443:198.51.100.7'],
    ]);
});
