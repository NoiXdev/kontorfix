<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function seedComposerCache(Upstream $up, string $package, string $version, string $distUrl): void
{
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id,
        'package_name' => $package,
        'payload' => ['packages' => [$package => [[
            'name' => $package, 'version' => 'v'.$version, 'version_normalized' => $version,
            'dist' => ['type' => 'zip', 'url' => $distUrl, 'reference' => 'abc'],
        ]]]],
        'fetched_at' => now(),
    ]);
}

it('downloads a composer artifact from the upstream, caches it and streams it', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk()->assertHeader('content-type', 'application/zip');

    // cached on disk
    expect(Storage::disk('artifacts')->allFiles())->not->toBe([]);

    // second hit: from the cache, no further upstream call
    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertOk();
    Http::assertNothingSent();
});

it('downloads an npm tarball from the upstream and streams octet-stream', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://reg.test']);
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id, 'package_name' => 'left-pad',
        'payload' => ['name' => 'left-pad', 'versions' => ['1.0.0' => ['dist' => ['tarball' => 'https://reg.test/left-pad/-/left-pad-1.0.0.tgz']]]],
        'fetched_at' => now(),
    ]);
    Http::fake(['reg.test/*' => Http::response('tarball-bytes', 200)]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/npm/{$up->id}/left-pad/-/left-pad-1.0.0.tgz")
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('401 without token, 404 for an upstream of another group', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $otherUp = Upstream::factory()->create(['type' => PackageType::Composer]); // belongs to another group
    seedComposerCache($otherUp, 'acme/demo', '1.0.0.0', 'https://cdn.test/x.zip');

    $this->get("/r/kadenz/proxy/composer/{$otherUp->id}/acme/demo/1.0.0.0")->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$otherUp->id}/acme/demo/1.0.0.0")->assertNotFound();
});

it('404 when the requested version is not in the cached metadata', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/a.zip');

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/9.9.9.0")->assertNotFound();
});

it('enforces strict mode on the download route even with cached metadata', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test', 'policy' => UpstreamPolicy::Strict]);
    // Metadata is in the cache (e.g. from before strict mode was enabled), but the package is NOT allowlisted.
    seedComposerCache($up, 'evil/pkg', '1.0.0.0', 'https://cdn.test/evil.zip');
    Http::fake(['cdn.test/*' => Http::response('bytes', 200)]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/evil/pkg/1.0.0.0")->assertNotFound();
    Http::assertNothingSent();

    // Once allowlisted: downloadable.
    $up->allowedPackages()->create(['name' => 'evil/pkg']);
    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/evil/pkg/1.0.0.0")->assertOk();
});

it('refuses a hostile upstream dist url pointing at an internal address', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'http://169.254.169.254/latest/meta-data/');
    Http::fake();

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertStatus(422);
    Http::assertNothingSent(); // the internal address must never be requested

    // file:// as well.
    UpstreamMetadataCache::where('upstream_id', $up->id)->delete();
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'file:///etc/passwd');
    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertStatus(422);
    Http::assertNothingSent();
});

it('refuses a dist url whose host resolves to an internal address', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    // Two shapes of the same defect, and they are caught by different branches.
    //
    //  - `2130706433` == 127.0.0.1: not an IP literal, so filter_var treats it as a
    //    hostname and only the resolver detects the target. It is handed to the *real*
    //    system resolver on purpose (Tests\Support\FixtureHostResolver), because how the
    //    C library decodes it is exactly the property production relies on. This case
    //    used to take the fail-closed branch under the old blanket stub and therefore
    //    did not test what its comment claimed.
    //  - `vault.internal` is an ordinary name that resolves into a private range — the
    //    property isSafeResolving() exists for, and which had no coverage at all.
    foreach (['http://2130706433/latest/meta-data/', 'http://vault.internal/secret'] as $distUrl) {
        UpstreamMetadataCache::where('upstream_id', $up->id)->delete();
        seedComposerCache($up, 'acme/demo', '1.0.0.0', $distUrl);
        Http::fake();

        $this->withHeaders(tokenHeaderFor($group))
            ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertStatus(422);
        Http::assertNothingSent(); // the address that resolves internally must never be requested
    }
});

it('refuses an upstream redirect to a host that resolves to an internal address', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'evil/pkg', '1.0.0.0', 'https://cdn.test/evil.zip');

    // Redirect target is decimal-encoded loopback (127.0.0.1) — not an IP literal,
    // only the DNS resolution of the re-validated hop detects it as internal.
    Http::fake(['cdn.test/*' => Http::response('', 302, ['Location' => 'http://2130706433/meta'])]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/evil/pkg/1.0.0.0")->assertStatus(502);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '2130706433'));
});

it('404s a download through a disabled upstream', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test', 'enabled' => false]);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/a.zip');

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertNotFound();
});

it('follows a safe upstream redirect (like github zipball) to the final artifact', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');

    Http::fake([
        'cdn.test/*' => Http::response('', 302, ['Location' => 'https://codeload.test/final.zip']),
        'codeload.test/*' => Http::response('zip-bytes', 200),
    ]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertOk();
});

it('refuses an upstream redirect that points at an internal address', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'evil/pkg', '1.0.0.0', 'https://cdn.test/evil.zip');

    Http::fake(['cdn.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/meta'])]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/evil/pkg/1.0.0.0")->assertStatus(502);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));
});
