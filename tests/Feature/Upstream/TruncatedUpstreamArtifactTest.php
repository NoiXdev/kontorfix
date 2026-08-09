<?php

// `238a829` replaced a buffered `getBytes()` read with a chunk loop that breaks on
// `fread() === false || ''` and then commits whatever arrived to the FINAL cache key. The
// buffered read it replaced raised on a short body (curl error 18) and cached nothing, so
// the rewrite converted a loud failure into a silent, durable one: a reset, a truncated
// body and a socket stall were all indistinguishable from a clean EOF, and `hasArtifact()`
// then reported a hit for every later request by every tenant on that upstream.
//
// The shipped configuration is the vulnerable one: `['stream' => true]` routes to Guzzle's
// StreamHandler while `allow_url_fopen` is on, which makes `timeout(30)` a *read* timeout —
// a mid-body stall returns '' and takes the break branch.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

function seedTruncationDist(Upstream $up, string $name, string $version, string $url): void
{
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id,
        'package_name' => $name,
        'payload' => ['packages' => [$name => [[
            'name' => $name, 'version' => "v{$version}", 'version_normalized' => $version,
            'dist' => ['type' => 'zip', 'url' => $url, 'reference' => 'abc'],
        ]]]],
        'fetched_at' => now(),
    ]);
}

/** A complete body that reaches EOF cleanly. */
function completeStream(string $body)
{
    $handle = fopen('php://temp', 'w+b');
    fwrite($handle, $body);
    rewind($handle);

    return $handle;
}

it('does not cache a body shorter than its content-length', function () {
    Storage::fake('artifacts');

    $bytes = app(UpstreamCache::class)->relayArtifact(completeStream('only-half'), 'proxy/x/short.zip', 40);

    // The client still gets what arrived — refusing to serve is not the fix.
    expect($bytes)->toBe(9)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('caches a body that matches its content-length', function () {
    // Reachability anchor: identical call, only the declared length differs. Proves the
    // refusal above comes from the length comparison and not from the budget check, the
    // per-artifact cap or the staging move.
    Storage::fake('artifacts');

    $bytes = app(UpstreamCache::class)->relayArtifact(completeStream('nine-char'), 'proxy/x/whole.zip', 9);

    expect($bytes)->toBe(9)
        ->and(Storage::disk('artifacts')->allFiles())->toBe(['proxy/x/whole.zip']);
});

it('does not cache when a real socket read times out and no content-length was given', function () {
    // Chunked upstreams send no Content-Length, so the length comparison cannot fire. This
    // is a genuine stalled socket, not a mock: the peer stays open and sends nothing more,
    // so the read hits its timeout — which is what `timeout(30)` becomes under the
    // StreamHandler. Verified against the real stream: `fread()` returns FALSE here (not
    // ''), with `feof()` false and `timed_out` true, so this pins the read-error signal.
    Storage::fake('artifacts');

    [$peer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($peer, 'partial-body');
    stream_set_timeout($reader, 0, 200000);

    $bytes = app(UpstreamCache::class)->relayArtifact($reader, 'proxy/x/stalled.zip');
    fclose($peer);

    expect($bytes)->toBe(12)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('does not cache when the body ends short of eof without a read error', function () {
    // The other half of the chunked case, and the one that isolates the EOF signal: a
    // non-blocking socket with nothing left to read returns '' — not false — with `feof()`
    // still false and `timed_out` false. Without the `reachedEof` term this partial body
    // would be committed, so this is what keeps that term honest.
    Storage::fake('artifacts');

    [$peer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($peer, 'partial-body');
    stream_set_blocking($reader, false);

    $bytes = app(UpstreamCache::class)->relayArtifact($reader, 'proxy/x/short-eof.zip');
    fclose($peer);

    expect($bytes)->toBe(12)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('still caches a chunked body that reaches eof cleanly', function () {
    // Reachability anchor for the stall case: also no declared length, but the transfer
    // completed. Caching must not be switched off wholesale for chunked upstreams.
    Storage::fake('artifacts');

    $bytes = app(UpstreamCache::class)->relayArtifact(completeStream('partial-body'), 'proxy/x/chunked.zip');

    expect($bytes)->toBe(12)
        ->and(Storage::disk('artifacts')->allFiles())->toBe(['proxy/x/chunked.zip']);
});

it('logs the short relay instead of failing silently', function () {
    Storage::fake('artifacts');
    Log::spy();

    app(UpstreamCache::class)->relayArtifact(completeStream('half'), 'proxy/x/logged.zip', 99);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []) => str_contains($message, 'truncated')
            && $context['path'] === 'proxy/x/logged.zip'
            && $context['received'] === 4
            && $context['expected'] === 99,
    );
});

it('does not durably cache a truncated artifact fetched over http', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 1048576]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedTruncationDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');

    // The upstream announces 4096 bytes and delivers 9 — a reset, a truncated body or a
    // stalled socket all look like this to the relay.
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200, ['Content-Length' => '4096'])]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk()->streamedContent();

    // Nothing was committed, so the next request re-fetches instead of serving a poisoned
    // artifact to every tenant on this upstream until the 30-day prune ages it out.
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('still caches a complete artifact fetched over http', function () {
    // Reachability anchor for the HTTP case: same route, same registry, same upstream,
    // same fake — only the declared Content-Length is honest.
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 1048576]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedTruncationDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200, ['Content-Length' => '9'])]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk()->streamedContent();

    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/composer/acme/demo/1.0.0.0.zip");
});
