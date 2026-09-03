<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;

it('redirects an old bare-slug url to the organization-scoped one', function () {
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/packages.json")
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json");
});

it('preserves the remaining path and the query string', function () {
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/p2/acme/tools.json?x=1")
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/p2/acme/tools.json?x=1");
});

it('404s an unknown slug rather than redirecting', function () {
    $this->get('/r/does-not-exist/packages.json')->assertNotFound();
});

it('lets the canonical form win where both could match', function () {
    // Organization "acme" owns registry "tools", and a *different* organization owns a
    // registry whose own slug is "acme". /r/acme/tools genuinely matches both readings:
    // the canonical {orgSlug}/{groupSlug} pair, and the legacy {groupSlug}/{rest} form
    // where "acme" would be a bare registry slug and "tools/packages.json" the rest.
    // Canonical must win — a wrong answer here would silently serve one tenant's
    // registry under another tenant's name.
    //
    // UnclaimedSlug refuses to create this pair through the application (organization and
    // registry slugs share one namespace), and the upgrade migration refuses to run while
    // it already exists. So this state is only constructible directly against the models,
    // bypassing validation entirely — which is exactly why the rule and the migration
    // refusal exist: the application can no longer produce it, only a test can.
    $acmeOrg = Organization::factory()->create(['slug' => 'acme']);
    $canonical = Group::factory()->for($acmeOrg)->create(['slug' => 'tools', 'public' => true]);
    $package = Package::factory()->inOrgOf($canonical)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $canonical->packages()->attach($package);

    $legacyOrg = Organization::factory()->create();
    Group::factory()->for($legacyOrg)->create(['slug' => 'acme', 'public' => true]);

    $this->get('/r/acme/tools/packages.json')->assertOk();
});
