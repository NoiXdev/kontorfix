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
