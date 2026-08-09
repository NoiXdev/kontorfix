<?php

// A02 — a redirect hands its `Location` to the client. Anything in that URL is
// disclosed to whoever asked, and on a public group that is an anonymous reader with no
// token at all. `upstreams.url` is the only place a Basic-auth mirror credential can
// live (UpstreamClient sends `auth_token` as a Bearer header and nothing else), so a
// redirect built by concatenating it is a cleartext credential disclosure to the lowest
// tier the product has — and onward into CI logs and every proxy on the path.
//
// This is a class, not one line: every registry fallthrough that could grow a redirect
// is pinned here, not just the PyPI one the audit found.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use App\Services\Health\HealthService;
use App\Support\CredentialUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const MIRROR_USER = 'svc-mirror';
const MIRROR_PASSWORD = 's3cr3t-mirror-pw';
const CREDENTIALLED_MIRROR = 'https://'.MIRROR_USER.':'.MIRROR_PASSWORD.'@mirror.example/repository';

function publicRegistryGroup(string $slug): Group
{
    return Group::factory()->for(Organization::factory())->create(['slug' => $slug, 'public' => true]);
}

/** Nothing in the status line, the headers or the body may carry the mirror credential. */
function expectNoMirrorCredential(TestResponse $response): void
{
    $serialized = json_encode($response->headers->all()).$response->getContent();

    expect($serialized)->not->toContain(MIRROR_PASSWORD)
        ->and($serialized)->not->toContain(MIRROR_USER)
        ->and($serialized)->not->toContain('mirror.example');
}

// ---------------------------------------------------------------------------
// PyPI — the reported instance.
// ---------------------------------------------------------------------------

it('reaches the pypi upstream fallthrough anonymously on a public group', function () {
    // The anchor for the test below. Without it, a 404 there proves nothing: the route
    // constraint, the type middleware or the access check could each produce one before
    // the fallthrough is ever entered. This asserts the whole path is live, and reached
    // with no token whatsoever.
    $group = publicRegistryGroup('anchor');
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true,
    ]);

    $this->get('/r/anchor/simple/requests/')
        ->assertRedirect('https://pypi.org/simple/requests/');
});

it('does not hand the mirror credential to an anonymous client in a redirect', function () {
    $group = publicRegistryGroup('leak');
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Python, 'url' => CREDENTIALLED_MIRROR, 'enabled' => true,
    ]);

    $response = $this->get('/r/leak/simple/requests/');

    // Not redirected at all: a credential-free redirect to a private mirror would fail
    // the client with a 401 anyway, while still naming the internal host and telling it
    // which project was asked for.
    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Location'))->toBeNull();
    expectNoMirrorCredential($response);

    // And not a *redacted* one either. Redaction is for showing a URL to a reader; a
    // `Location` is used, so `***@` would be a broken credential the client tries to
    // authenticate with, and it still says a credential exists and on which host.
    expect((string) $response->getContent())->not->toContain(CredentialUrl::MARKER.'@');
});

// ---------------------------------------------------------------------------
// Composer and npm — the sibling fallthroughs. They fetch server-side and rewrite
// artifact URLs to `/proxy/...` today. Pinned so neither grows a redirect later.
// ---------------------------------------------------------------------------

it('never leaks the mirror credential through the composer metadata fallthrough', function () {
    Http::fake(['*' => Http::response([
        'packages' => ['acme/widget' => [[
            'name' => 'acme/widget', 'version' => '1.0.0', 'version_normalized' => '1.0.0.0',
            'dist' => ['type' => 'zip', 'url' => 'https://mirror.example/repository/dist/widget-1.0.0.zip', 'reference' => 'abc'],
        ]]],
    ], 200)]);

    $group = publicRegistryGroup('composer-mirror');
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Composer, 'url' => CREDENTIALLED_MIRROR, 'enabled' => true,
    ]);

    $response = $this->get('/r/composer-mirror/p2/acme/widget.json');

    expect($response->getStatusCode())->toBe(200);
    expectNoMirrorCredential($response);
});

it('never leaks the mirror credential through the npm packument fallthrough', function () {
    Http::fake(['*' => Http::response([
        'name' => 'left-pad',
        'dist-tags' => ['latest' => '1.0.0'],
        'versions' => ['1.0.0' => [
            'name' => 'left-pad', 'version' => '1.0.0',
            'dist' => ['tarball' => 'https://mirror.example/repository/left-pad/-/left-pad-1.0.0.tgz'],
        ]],
    ], 200)]);

    $group = publicRegistryGroup('npm-mirror');
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Npm, 'url' => CREDENTIALLED_MIRROR, 'enabled' => true,
    ]);

    $response = $this->get('/r/npm-mirror/left-pad');

    expect($response->getStatusCode())->toBe(200);
    expectNoMirrorCredential($response);
});

// ---------------------------------------------------------------------------
// A silently disabled fallthrough is the mistake this branch keeps making. The
// operator has to be able to see why upstream resolution stopped working.
// ---------------------------------------------------------------------------

it('reports a credential-carrying python upstream on the operator health page', function () {
    $group = publicRegistryGroup('reported');
    $upstream = Upstream::factory()->for($group)->create([
        'type' => PackageType::Python, 'url' => CREDENTIALLED_MIRROR, 'enabled' => true,
    ]);

    $check = collect(app(HealthService::class)->checks())
        ->firstWhere('key', 'upstream:'.$upstream->id);

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('Credential');
    // The health page is super-admin only, but it is still a read surface and every
    // other one redacts. No reason for this to be the exception.
    expect($check['label'])->not->toContain(MIRROR_PASSWORD);
});

it('keeps the dependency-confusion guard ahead of the credential check', function () {
    // Ordering matters: a locally-known private name must 404 because it is local, never
    // because of anything to do with the upstream's URL.
    $group = publicRegistryGroup('confusion');
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true,
    ]);
    $other = publicRegistryGroup('elsewhere');
    $other->packages()->attach(
        Package::factory()->create(['type' => PackageType::Python, 'name' => 'internal-lib'])
    );

    $this->get('/r/confusion/simple/internal-lib/')->assertNotFound();
});
