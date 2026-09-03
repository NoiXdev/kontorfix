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

    // Pinned on the response body, not just the status: ComposerController::root() 200s
    // for any public composer-enabled group regardless of what packages it holds, so an
    // assertOk() alone would pass even if the *wrong* registry answered. metadata-url
    // carries this group's own path (RegistryUrl::path()), so it identifies which
    // registry actually answered.
    $this->get('/r/acme/tools/packages.json')
        ->assertOk()
        ->assertJsonPath('metadata-url', '/r/acme/tools/p2/%package%.json')
        ->assertJsonPath('available-packages', ['acme/tools']);
});

it('refuses to guess when a legacy slug is shared by two organizations', function () {
    // Before this branch `groups.slug` had its own instance-wide unique index, so a bare
    // slug always named exactly one registry. Task 1 scoped that uniqueness to
    // (organization_id, slug), so two organizations may now legitimately hold a registry
    // with the same slug — a state OrgScopedSlugTest's "lets two organizations hold the
    // same registry slug" advertises as a feature. A bare legacy URL can no longer assume
    // its slug names one registry, and picking either candidate would silently serve one
    // tenant's registry to a client that meant the other's — dependency confusion if the
    // wrong guess is public, a confusing 401/404 if it's private, and nondeterministic
    // either way since the lookup carries no ORDER BY. Refuse instead of guessing.
    Group::factory()->create(['slug' => 'shared', 'public' => true]);
    Group::factory()->create(['slug' => 'shared', 'public' => true]);

    $this->get('/r/shared/packages.json')->assertNotFound();
});

it('redirects the exact per-project url pip requests, not just the index root', function () {
    // pip does not follow a redirect for the index root and stop there — for every package
    // it constructs the project URL itself from the configured index-url:
    // GET /r/{slug}/simple/{project}/. That URL's shape satisfies the *canonical* route
    // before it ever reaches LegacySlugRedirectController's own route: the router reads it
    // as {orgSlug}={slug}, {groupSlug}="simple" (the prefix always consumes exactly two
    // segments), and what's left — "some-project" — then matches npm's bare {package}
    // pattern (registered for exactly this shape, one segment after the prefix). That is a
    // genuine route match, so the dedicated legacy route never gets tried at all — the
    // fallback has to live in ResolveRegistryContext, at the point where the organization
    // lookup for "{slug}" comes back empty.
    $group = Group::factory()->create(['slug' => 'oldslug', 'public' => true]);
    $org = $group->organization;

    $this->get('/r/oldslug/simple/some-project/')
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/simple/some-project/");
});

it('refuses to guess pip\'s per-project url too, when the slug is shared', function () {
    // Same ambiguity as the plain-route case above, but exercised through the
    // ResolveRegistryContext fallback rather than LegacySlugRedirectController.
    Group::factory()->create(['slug' => 'shared-pip', 'public' => true]);
    Group::factory()->create(['slug' => 'shared-pip', 'public' => true]);

    $this->get('/r/shared-pip/simple/some-project/')->assertNotFound();
});

it('redirects an npm publish (PUT) instead of 405ing it', function () {
    // Before this task a bare-slug URL 404d for every method. Adding a GET-only legacy
    // route would turn that 404 into a 405 for exactly the write that matters — npm
    // reads .npmrc for both installs and `npm publish`. A write also can't take a 301:
    // most HTTP clients only replay a 301/302 for GET, so a redirected PUT could silently
    // become a GET against the canonical URL and drop the publish payload. 308 is
    // permanent AND method-and-body-preserving.
    $group = Group::factory()->create(['slug' => 'oldslug-npm', 'public' => true]);
    $org = $group->organization;

    $this->put('/r/oldslug-npm/some-package')
        ->assertStatus(308)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/some-package");
});

it('redirects a twine upload (POST) to the registry root instead of 405ing it', function () {
    $group = Group::factory()->create(['slug' => 'oldslug-pypi', 'public' => true]);
    $org = $group->organization;

    $this->post('/r/oldslug-pypi')
        ->assertStatus(308)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}");
});

it('passes the query string through byte-for-byte instead of reordering it', function () {
    // Request::getQueryString() runs the query through parse_str + ksort + rebuild, which
    // reorders "b=2&a=1" into "a=1&b=2". A customer's URL might carry a meaningful order
    // (or a repeated key, which the same normalisation would collapse) — the redirect
    // should hand it back exactly as the client sent it.
    $group = Group::factory()->create(['slug' => 'oldslug-query', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/packages.json?b=2&a=1")
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json?b=2&a=1");
});

it('passes a percent-encoded rest segment through unchanged instead of corrupting it', function () {
    // The route parameter for the trailing path is already rawurldecoded by the time a
    // controller sees it, so building the redirect from it would turn a literal %2F into
    // an actual "/" (silently reshaping the path) and a %23 into "#" (silently truncating
    // everything the client sent after it, since "#" starts a fragment). Reconstructing
    // the target from the untouched REQUEST_URI instead means the encoding survives.
    $group = Group::factory()->create(['slug' => 'oldslug-enc', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/p2/vendor/na%20me.json")
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/p2/vendor/na%20me.json");
});
