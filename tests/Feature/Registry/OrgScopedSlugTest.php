<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\UniqueConstraintViolationException;

it('lets two organizations hold the same registry slug', function () {
    $a = Group::factory()->create(['slug' => 'packages']);
    $b = Group::factory()->create(['slug' => 'packages']);

    expect($a->organization_id)->not->toBe($b->organization_id)
        ->and($b->exists)->toBeTrue();
});

it('still refuses the same slug twice inside one organization', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create(['slug' => 'packages']);

    expect(fn () => Group::factory()->for($org)->create(['slug' => 'packages']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('serves a registry at the organization-scoped url', function () {
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $group->packages()->attach($package);

    $org = $group->organization;

    $this->get("/r/{$org->slug}/{$group->slug}/packages.json")->assertOk();
});

it('redirects the bare slug url to the organization-scoped one', function () {
    // preUpgrade(): only a registry that existed at migration time carries a frozen
    // `legacy_slug`, and that column — never the live slug — is what the redirect matches.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'packages', 'public' => true]);
    $org = $group->organization;

    // The legacy form is now a 301, not a 200 and not a 404 — see LegacySlugRedirectTest
    // for the full behaviour (path/query preservation, unknown-slug 404, precedence).
    $this->get("/r/{$group->slug}/packages.json")
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json");
});

it('does not serve a registry under another organization slug', function () {
    // Both segments are part of the lookup: the slug alone no longer identifies a
    // registry, so a registry addressed under a foreign organization must not resolve.
    // Without this, one tenant could read another's registry through its own org slug.
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $group->packages()->attach($package);

    $stranger = Organization::factory()->create(['slug' => 'stranger']);

    $this->get("/r/{$stranger->slug}/{$group->slug}/packages.json")->assertStatus(404);
});

it('hands the controller the route parameters that follow the two-segment prefix', function () {
    // The controller action knows neither URL segment, and Laravel's controller dispatch is
    // purely positional: an {orgSlug} left on the route arrives as the action's first
    // argument — {vendor} here — shifting every later parameter by one, so the metadata
    // lookup asks for the wrong package. Hence the two forgetParameter() calls in
    // ResolveRegistryContext; this is what proves they are both there.
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    PackageVersion::factory()->for($package)->create();
    $group->packages()->attach($package);

    $this->getJson(registryPath($group).'/p2/acme/tools.json')
        ->assertOk()
        ->assertJsonStructure(['packages' => ['acme/tools']]);
});
