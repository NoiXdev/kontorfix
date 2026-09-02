<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Package;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;

/**
 * The dependency-confusion guard, once the package namespace is organization-scoped.
 *
 * The guard answers "does this organization host this name?" and suppresses the upstream
 * fallthrough when it does. Both directions have to hold, and each of these tests is only
 * meaningful because the group HAS an upstream configured: without one the request 404s on
 * the missing upstream and every assertion below would pass for the wrong reason.
 */
it('does not fall through to upstream for a name this organization owns', function () {
    Http::fake();
    $group = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy,
    ]);
    // Owned by this organization but not assigned to this registry: still must not leak.
    Package::factory()->inOrgOf($group)->create(['type' => PackageType::Composer, 'name' => 'acme/tools']);

    $this->get("/r/{$group->slug}/p2/acme/tools.json")->assertNotFound();

    Http::assertNothingSent();
});

it('falls through to upstream for a name another organization owns', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $mine = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($mine)->create([
        'type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy,
    ]);
    $theirs = Group::factory()->create(['public' => true]);
    Package::factory()->inOrgOf($theirs)->create(['type' => PackageType::Composer, 'name' => 'acme/tools']);

    $this->get("/r/{$mine->slug}/p2/acme/tools.json");

    // Deliberate: another tenant's private `acme/tools` must not shadow my legitimate
    // upstream dependency of the same name. That shadowing is the confusion this guard
    // exists to prevent, pointed the wrong way.
    Http::assertSentCount(1);
});

it('does not fall through to npmjs for an npm name this organization owns', function () {
    Http::fake();
    $group = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org', 'policy' => UpstreamPolicy::Proxy,
    ]);
    Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'internal-lib']);

    $this->get("/r/{$group->slug}/internal-lib")->assertNotFound();

    Http::assertNothingSent();
});

it('falls through to npmjs for an npm name another organization owns', function () {
    Http::fake(['*' => Http::response(['name' => 'internal-lib', 'versions' => []], 200)]);
    $mine = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($mine)->create([
        'type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org', 'policy' => UpstreamPolicy::Proxy,
    ]);
    $theirs = Group::factory()->create(['public' => true]);
    Package::factory()->inOrgOf($theirs)->create(['type' => PackageType::Npm, 'name' => 'internal-lib']);

    $this->get("/r/{$mine->slug}/internal-lib");

    Http::assertSentCount(1);
});

it('falls through to the python upstream for a project another organization owns', function () {
    $mine = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($mine)->create([
        'type' => PackageType::Python, 'url' => 'https://pypi.org', 'policy' => UpstreamPolicy::Proxy,
    ]);
    $theirs = Group::factory()->create(['public' => true]);
    Package::factory()->inOrgOf($theirs)->create(['type' => PackageType::Python, 'name' => 'internal-lib']);

    $this->get("/r/{$mine->slug}/simple/internal-lib/")
        ->assertRedirect('https://pypi.org/simple/internal-lib/');
});
