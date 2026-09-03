<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;

it('redirects an old bare-slug url to the organization-scoped one', function () {
    $group = Group::factory()->preUpgrade()->create(['slug' => 'packages', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/packages.json")
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json");
});

it('preserves the remaining path and the query string', function () {
    $group = Group::factory()->preUpgrade()->create(['slug' => 'packages', 'public' => true]);
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

    // preUpgrade(): the legacy reading has to be genuinely available, or the test proves
    // only that a non-existent legacy address loses. This registry really does still answer
    // /r/acme/… — and the canonical route still has to win over it.
    $legacyOrg = Organization::factory()->create();
    Group::factory()->for($legacyOrg)->preUpgrade()->create(['slug' => 'acme', 'public' => true]);

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

/**
 * Replaces "refuses to guess when a legacy slug is shared by two organizations". That test
 * built two registries sharing one slug and pinned a 404, because the redirector matched
 * the live `slug` and could not tell which one a bare legacy URL meant. The state is still
 * legal — two organizations sharing a registry slug is the point of the branch — but it is
 * no longer ambiguous, and pinning a 404 would now pin the *bug*: the incumbent's clients
 * going dark the moment a stranger picks the same name. `legacy_slug` is what the redirect
 * matches, it is unique, and only the incumbent has one.
 */
it('does not let a registry created after the upgrade capture an incumbent legacy address', function () {
    // A different organization now creates a registry with the same slug — legal since the
    // slug is only unique per organization, and available to any org admin with no
    // cross-org rights whatsoever. Created after the upgrade, so no legacy address.
    $newcomer = Group::factory()->create(['slug' => 'shared', 'public' => true]);

    // The incumbent was here when the instance was upgraded, so /r/shared/… is frozen to it.
    $incumbent = Group::factory()->preUpgrade()->create(['slug' => 'shared', 'public' => true]);

    expect($newcomer->legacy_slug)->toBeNull()
        ->and($newcomer->organization_id)->not->toBe($incumbent->organization_id);

    // Pinned on the redirect target, not merely on a 301: the failure this guards against
    // is answering the *wrong* registry, which a status assertion alone would not see.
    $this->get('/r/shared/packages.json')
        ->assertStatus(301)
        ->assertRedirect(registryPath($incumbent).'/packages.json');
});

it('stops answering a legacy address once the registry gives up that slug', function () {
    // The documented decision is that a slug change moves the address and leaves no alias.
    // Keeping the frozen address across a rename would break that both ways: the operator
    // could not actually retire an address, and the released name would stay reserved
    // instance-wide against every other tenant.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'renamed-away', 'public' => true]);

    $group->update(['slug' => 'the-new-name']);

    expect($group->fresh()->legacy_slug)->toBeNull();

    $this->get('/r/renamed-away/packages.json')->assertNotFound();
});

it('does not hand a client stranded by an organization rename a stranger registry', function () {
    // The one cross-tenant *serve* the review could construct against the slug-matching
    // redirector. Organization A is `alpha` and owns registry `tools`; a client is
    // configured for /r/alpha/tools/…. A renames itself, which frees `alpha` — it names no
    // organization any more, so UnclaimedSlug now happily lets organization C create a
    // *registry* called `alpha`. The stale client's URL has no canonical reading (there is
    // no /-/{file} endpoint one segment after a two-segment prefix), so it falls to the
    // legacy route, where matching the live slug would have found C's registry and 301'd
    // A's client onto C's tarball. Nothing may answer this address.
    $orgA = Organization::factory()->create(['slug' => 'alpha']);
    Group::factory()->for($orgA)->preUpgrade()->create(['slug' => 'tools', 'public' => true]);
    $orgA->update(['slug' => 'alpha-gmbh']);

    $orgC = Organization::factory()->create();
    Group::factory()->for($orgC)->create(['slug' => 'alpha', 'public' => true]);

    $this->get('/r/alpha/tools/-/tools-1.0.0.tgz')
        ->assertNotFound()
        ->assertHeaderMissing('Location');
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
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug', 'public' => true]);
    $org = $group->organization;

    $this->get('/r/oldslug/simple/some-project/')
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/simple/some-project/");
});

/**
 * Replaces "refuses to guess pip's per-project url too, when the slug is shared", for the
 * same reason as its plain-route sibling above: the shared slug is no longer ambiguous, so
 * a 404 here would pin the incumbent's pip clients going dark rather than the safety
 * property. Exercised through the ResolveRegistryContext fallback rather than
 * LegacySlugRedirectController — both call the same resolver, and both have to be pinned.
 */
it('keeps pip\'s per-project url on the incumbent when a newcomer shares the slug', function () {
    Group::factory()->create(['slug' => 'shared-pip', 'public' => true]);
    $incumbent = Group::factory()->preUpgrade()->create(['slug' => 'shared-pip', 'public' => true]);

    $this->get('/r/shared-pip/simple/some-project/')
        ->assertStatus(301)
        ->assertRedirect(registryPath($incumbent).'/simple/some-project/');
});

it('redirects an npm publish (PUT) instead of 405ing it', function () {
    // Before this task a bare-slug URL 404d for every method. Adding a GET-only legacy
    // route would turn that 404 into a 405 for exactly the write that matters — npm
    // reads .npmrc for both installs and `npm publish`. A write also can't take a 301:
    // most HTTP clients only replay a 301/302 for GET, so a redirected PUT could silently
    // become a GET against the canonical URL and drop the publish payload. 308 is
    // permanent AND method-and-body-preserving.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-npm', 'public' => true]);
    $org = $group->organization;

    $this->put('/r/oldslug-npm/some-package')
        ->assertStatus(308)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/some-package");
});

it('redirects a twine upload (POST) to the registry root instead of 405ing it', function () {
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-pypi', 'public' => true]);
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
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-query', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/packages.json?b=2&a=1")
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json?b=2&a=1");
});

it('passes a percent-encoded rest segment through unchanged instead of corrupting it', function () {
    // The route parameter for the trailing path is already rawurldecoded by the time a
    // controller sees it, so building the redirect from it would turn a literal %2F into
    // an actual "/" (silently reshaping the path) and a %23 into "#" (silently truncating
    // everything the client sent after it, since "#" starts a fragment). Reconstructing
    // the target from undecoded input instead means the encoding survives — getPathInfo()
    // slices REQUEST_URI without urldecoding it, so this holds after the base-URL fix below.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-enc', 'public' => true]);
    $org = $group->organization;

    $this->get("/r/{$group->slug}/p2/vendor/na%20me.json")
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/p2/vendor/na%20me.json");
});

it('redirects correctly when the application is deployed under a subdirectory', function () {
    // AppUrl's docblock supports an APP_URL carrying a subdirectory path, and
    // RegistryUrl::origin() propagates it. Counting the segments to drop off the raw
    // REQUEST_URI ignores that: under /sub/ the two dropped segments are "sub" and "r", so
    // the legacy slug segment stayed in the rest and the target came out with the registry
    // named twice — /sub/r/{org}/{group}/{group}/packages.json. getPathInfo() strips the
    // base URL first, so the two dropped segments are the ones actually meant.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-sub', 'public' => true]);
    $org = $group->organization;

    // A subdirectory deployment is two things at once: APP_URL carries the path (AppUrl
    // normalises it, PinUrlRoot roots every generated absolute URL at it), and the front
    // controller sits at /sub/index.php, from which Symfony derives the base URL that
    // getPathInfo() strips. Both have to be set, or the test proves only half of it.
    config(['app.url' => 'http://localhost/sub']);

    $this->withServerVariables([
        'SCRIPT_NAME' => '/sub/index.php',
        'SCRIPT_FILENAME' => '/sub/index.php',
        'PHP_SELF' => '/sub/index.php',
    ])->get("/sub/r/{$group->slug}/packages.json")
        ->assertStatus(301)
        ->assertRedirect("http://localhost/sub/r/{$org->slug}/{$group->slug}/packages.json");
});

it('redirects a HEAD request on a legacy url instead of 405ing it', function () {
    // What is actually at stake is the redirect *status*, not the routing. The routing
    // claim this comment used to make — that Route::match() drops HEAD unless it is listed
    // — was checked against this app's vendored Laravel 13.25 and refuted:
    // Illuminate\Routing\Route::__construct() appends HEAD to any method list containing
    // GET, so the route matches either way (routes/registry.php lists it anyway, and says
    // why). The real hazard is LegacySlugRedirector::respond(): HEAD is a read, but
    // Request::isMethod('get') is false for it, so a naive check hands a HEAD probe a
    // body-preserving 308 instead of the plain 301 every client expects for a read. HEAD is
    // not exotic here — package clients use it for existence and cache-validation checks.
    $group = Group::factory()->preUpgrade()->create(['slug' => 'oldslug-head', 'public' => true]);
    $org = $group->organization;

    $this->head("/r/{$group->slug}/packages.json")
        ->assertStatus(301)
        ->assertRedirect("/r/{$org->slug}/{$group->slug}/packages.json");
});

it('does not let the pip fallback swallow a legitimate canonical request', function () {
    // The ResolveRegistryContext fallback only ever fires when the organization lookup for
    // the first segment comes back empty — UnclaimedSlug keeps organization and registry
    // slugs in disjoint namespaces, so a *real* organization slug should never reach it.
    // Pin that directly rather than leaving it to follow from the gate condition: an
    // organization that genuinely owns a registry slugged "simple" — the exact literal
    // segment pip's index-url path uses — must resolve normally through the ordinary
    // two-segment lookup, not get treated as if "simple" in the first segment position
    // were itself a legacy registry slug pretending to be an organization.
    $org = Organization::factory()->create(['slug' => 'realorg']);
    $group = Group::factory()->for($org)->create(['slug' => 'simple', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $group->packages()->attach($package);

    $this->get('/r/realorg/simple/packages.json')
        ->assertOk()
        ->assertHeaderMissing('Location')
        ->assertJsonPath('metadata-url', '/r/realorg/simple/p2/%package%.json');
});
