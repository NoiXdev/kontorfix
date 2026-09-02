<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;

it('does not serve one organization a package owned by another', function () {
    $mine = Group::factory()->create(['public' => true]);
    $theirs = Group::factory()->create(['public' => true]);

    $foreign = Package::factory()->inOrgOf($theirs)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    // Attached to my registry by a pre-invariant row: resolution must still refuse it.
    $mine->packages()->attach($foreign);

    // The 404 is now single-sourced. It used to have two independent causes — findLocal()
    // refusing the foreign row, and the then-unscoped dependency-confusion guard spotting
    // it — and scoping the guard removed the second. findLocal() is and always was this
    // test's subject: unscope its organization_id constraint and this request returns 200
    // (the pivot row above makes the package accessible to a public group), so the test
    // goes red. The guard is covered separately in OrgScopedUpstreamFallthroughTest.
    $this->get("/r/{$mine->slug}/p2/acme/tools.json")->assertNotFound();
});

it('does not serve another organization\'s package even when an upstream can answer', function () {
    // The companion to the test above, covering the case it cannot: with an upstream
    // configured the request falls through instead of 404ing, so a status-code assertion
    // would no longer prove anything. What must hold either way is that the foreign row is
    // never SERVED — its version never appears in the response, and the answer demonstrably
    // came from the upstream. Were findLocal() unscoped again, the local metadata would be
    // returned and nothing would be sent, so both assertions below would fail.
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);

    $mine = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($mine)->create([
        'type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy,
    ]);

    $theirs = Group::factory()->create(['public' => true]);
    $foreign = Package::factory()->inOrgOf($theirs)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    PackageVersion::factory()->for($foreign)->create(['version_pretty' => 'v6.6.6']);
    $mine->packages()->attach($foreign);

    $res = $this->get("/r/{$mine->slug}/p2/acme/tools.json");

    expect($res->getContent())->not->toContain('v6.6.6');
    Http::assertSentCount(1);
});

it('serves a package owned by the addressed organization', function () {
    $group = Group::factory()->create(['public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $group->packages()->attach($package);

    $this->get("/r/{$group->slug}/p2/acme/tools.json")->assertOk();
});
