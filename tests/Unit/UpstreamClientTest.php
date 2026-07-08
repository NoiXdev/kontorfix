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

it('throws UpstreamException on a 500', function () {
    Http::fake(['repo.test/*' => Http::response('boom', 500)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    expect(fn () => app(UpstreamClient::class)->getJson($up, '/p2/x/y.json'))
        ->toThrow(UpstreamException::class);
});

it('fetches raw bytes for an artifact url', function () {
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');
});
