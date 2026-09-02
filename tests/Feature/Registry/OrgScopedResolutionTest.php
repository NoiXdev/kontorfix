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

    // No upstream is configured here, so the refusal is a flat 404. Note the provenance of
    // that 404 moved: it used to come from the (then unscoped) dependency-confusion guard
    // spotting the foreign row; now the guard is organization-scoped and ignores it, and
    // the 404 comes from there being nothing to fall through to. The test below covers the
    // same refusal with a live fallthrough path, where the status code is no longer 404.
    $this->get("/r/{$mine->slug}/p2/acme/tools.json")->assertNotFound();
});

it('does not serve another organization\'s package even when an upstream can answer', function () {
    // The companion to the test above. With an upstream configured the request now falls
    // through instead of 404ing, so a status-code assertion would no longer prove anything.
    // What must hold regardless of status code is that the foreign row is never SERVED:
    // its version never appears in the response, and the answer demonstrably came from
    // the upstream. Were findLocal() unscoped again, the local metadata would be returned
    // and nothing would be sent — both assertions below would fail.
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
