<?php

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use App\Services\Upstream\UpstreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// I1: getJson() muss — wie getBytes() — Redirects manuell folgen und jeden Hop
// gegen die SSRF-Regeln prüfen. Ein bösartiger Upstream darf einen Metadaten-
// Abruf nicht per 302 auf eine interne Adresse (127.0.0.1 / [::1]) umlenken.
it('refuses a metadata redirect to an internal ipv4 address', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data/']),
    ]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    expect(fn () => app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json'))
        ->toThrow(UpstreamException::class);

    // Das interne Ziel darf nie angefragt werden.
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

// I2: getBytes() setzt das Bearer-Token pro Hop neu — auch nach einem Redirect auf
// einen fremden Host. Ein bösartiger Upstream kann so das private Upstream-Token
// abgreifen. Das Token darf nur an den ursprünglichen Upstream-Host gehen; nach
// einem Host-Wechsel darf KEIN Authorization-Header mehr mitgehen.
it('drops the upstream auth token on a cross-host artifact redirect', function () {
    Http::fake([
        'cdn.test/a/*' => Http::response('', 302, ['Location' => 'https://evil-collector.example/loot.zip']),
        'evil-collector.example/*' => Http::response('zip-bytes', 200),
    ]);
    // Upstream-Host == Host der Artefakt-URL: der erste Hop trägt das Token legitim.
    $up = Upstream::factory()->create(['url' => 'https://cdn.test', 'auth_token' => 'secret-token']);

    expect(app(UpstreamClient::class)->getBytes($up, 'https://cdn.test/a/b.zip'))->toBe('zip-bytes');

    // Der fremde Host darf das private Bearer-Token nicht sehen.
    Http::assertSent(fn ($r) => $r->url() === 'https://evil-collector.example/loot.zip' && ! $r->hasHeader('Authorization'));
    // Der ursprüngliche Upstream-Host bekommt das Token weiterhin.
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
