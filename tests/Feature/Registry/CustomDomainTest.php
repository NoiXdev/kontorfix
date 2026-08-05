<?php

use App\Enums\PackageType;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;

it('serves composer packages.json at the domain root with a root-relative metadata-url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('serves p2 metadata at the domain root with a domain-root dist url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/p2/acme/demo.json');

    $res->assertOk()->assertJsonStructure(['packages' => ['acme/demo']]);
    $dist = data_get($res->json(), 'packages.acme/demo.0.dist.url');
    expect($dist)->toStartWith('http://packages.kadenz.test/dists/');
});

it('serves npm packument at the domain root', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme-demo', 'type' => PackageType::Npm]);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/acme-demo');

    $res->assertOk()->assertJsonPath('name', 'acme-demo');
});

it('returns 404 for an unknown host', function () {
    $this->getJson('http://not-a-registry.test/packages.json')->assertNotFound();
});

it('still serves the slug route unchanged after the domain-resolution refactor', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('does not shadow the main app routes with the registry catch-all', function () {
    $this->instanceAlreadySetUp();

    // /login is a real web route and must not be hijacked by the npm-{package} catch-all.
    $this->get('/login')->assertOk();
});

it('gates a custom domain by the group ACL: a wrong-group token gets 404', function () {
    $victim = Group::factory()->for(Organization::factory())->create(['slug' => 'victim']);
    Domain::factory()->for($victim)->create(['hostname' => 'packages.victim.test']);
    $attacker = Group::factory()->for(Organization::factory())->create(['slug' => 'attacker']);

    // Completely without a token: 401 (first — otherwise withHeaders would persist the token into the next call).
    $this->withHeaders(['Host' => 'packages.victim.test'])
        ->getJson('http://packages.victim.test/packages.json')->assertUnauthorized();

    // Token from a different group, host-spoofed against the victim domain -> 404.
    $this->withHeaders(array_merge(tokenHeaderFor($attacker), ['Host' => 'packages.victim.test']))
        ->getJson('http://packages.victim.test/packages.json')->assertNotFound();
});

it('returns 502 on an upstream error even on a custom domain', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    Http::fake(['*' => Http::response('boom', 500)]);

    // Raw GET (no Accept: json) against the domain root -> must still return 502.
    $this->withHeaders(array_merge(tokenHeaderFor($group), ['Host' => 'packages.kadenz.test']))
        ->get('http://packages.kadenz.test/p2/some/pkg.json')->assertStatus(502);
});
