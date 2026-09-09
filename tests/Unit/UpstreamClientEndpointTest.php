<?php

use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use App\Services\Upstream\UpstreamClient;
use App\Services\Upstream\UpstreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// A plain implementation, deliberately NOT an Upstream/MirrorSource — UpstreamClient must
// work against the UpstreamEndpoint interface alone, not against a concrete model.
class FakeUpstreamEndpoint implements UpstreamEndpoint
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token = null,
    ) {}

    public function endpointUrl(): string
    {
        return $this->url;
    }

    public function endpointToken(): ?string
    {
        return $this->token;
    }
}

it('accepts a plain UpstreamEndpoint and attaches the token on a same-host https request', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $endpoint = new FakeUpstreamEndpoint('https://repo.test', 'tok');

    $data = app(UpstreamClient::class)->getJson($endpoint, '/p2/acme/demo.json');

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->url(), 'repo.test/p2/acme/demo.json'));
});

// I1 (security property this feature leans on): the bearer token travels only to the
// original host over HTTPS — never to a host a redirect points at.
it('drops the token from a plain UpstreamEndpoint on a cross-host redirect', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'https://mirror.test/p2/acme/demo.json']),
        'mirror.test/*' => Http::response(['ok' => true], 200),
    ]);
    $endpoint = new FakeUpstreamEndpoint('https://repo.test', 'tok');

    $data = app(UpstreamClient::class)->getJson($endpoint, '/p2/acme/demo.json');

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'mirror.test') && ! $r->hasHeader('Authorization'));
});

it('does not send the token over a cleartext http hop for a plain UpstreamEndpoint', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $endpoint = new FakeUpstreamEndpoint('http://repo.test', 'tok');

    app(UpstreamClient::class)->getJson($endpoint, '/p2/acme/demo.json');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'repo.test') && ! $r->hasHeader('Authorization'));
});

// I2 (the other security property): every hop — including a hop reached through a plain
// UpstreamEndpoint, not just an Upstream model — is re-checked against UrlSafety.
it('refuses a redirect to an internal address for a plain UpstreamEndpoint', function () {
    Http::fake([
        'repo.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data/']),
    ]);
    $endpoint = new FakeUpstreamEndpoint('https://repo.test', 'tok');

    expect(fn () => app(UpstreamClient::class)->getJson($endpoint, '/p2/acme/demo.json'))
        ->toThrow(UpstreamException::class);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
});

// MirrorSource must be usable purely through the UpstreamEndpoint interface — no
// instanceof-Upstream branching anywhere in UpstreamClient.
it('accepts a MirrorSource as an UpstreamEndpoint', function () {
    Http::fake(['mirror-src.test/*' => Http::response(['ok' => true], 200)]);
    $source = MirrorSource::factory()->create(['url' => 'https://mirror-src.test', 'auth_token' => 'mirror-tok']);

    $data = app(UpstreamClient::class)->getJson($source, '/p2/acme/demo.json');

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer mirror-tok'));
});
