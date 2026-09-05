<?php

use App\Models\Group;
use App\Models\Organization;
use App\Services\Portal\PortalUrl;
use App\Services\Registry\RegistryUrl;

it('changes an organization slug and with it every registry url it owns', function () {
    $org = Organization::factory()->create(['slug' => 'old-org']);
    $group = Group::factory()->for($org)->create(['slug' => 'packages', 'public' => true]);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name,
        'slug' => 'new-org',
        'notification_cadence' => cadenceOf($org),
    ])->assertRedirect();

    $this->get('/r/new-org/packages/packages.json')->assertOk();
    $this->get('/r/old-org/packages/packages.json')->assertNotFound();
    expect($group->fresh()->slug)->toBe('packages');
});

/**
 * The one exception the slug confirmation now names. Its previous sentence — "Weiterleitungen
 * von den alten Adressen gibt es in keinem der beiden Fälle" — was conservative but false for
 * every registry that predates the slug-scoping migration: `LegacySlugRedirector::target()`
 * builds its destination through `RegistryUrl::path()`, which reads the organization's CURRENT
 * slug, so a one-segment /r/{registry} address follows the rename instead of breaking with it.
 *
 * Pinned here rather than left to the copy, so the half-sentence the dialog gained is a claim
 * the suite keeps true. Both directions are asserted: the two-segment address under the OLD
 * organization slug still 404s (the first case in this file), and the one-segment one lands on
 * the NEW one — a target built from a frozen organization slug would answer the old path and
 * pass any assertion that only checked for a 301.
 */
it('keeps the one-segment legacy address pointing at the registry through a rename', function () {
    $org = Organization::factory()->create(['slug' => 'old-org']);
    $group = Group::factory()->preUpgrade()->for($org)->create(['slug' => 'packages', 'public' => true]);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name,
        'slug' => 'new-org',
        'notification_cadence' => cadenceOf($org),
    ])->assertRedirect();

    $this->get('/r/packages/packages.json')
        ->assertStatus(301)
        ->assertRedirect('/r/new-org/packages/packages.json');
});

it('refuses an organization slug that is already taken', function () {
    Organization::factory()->create(['slug' => 'taken']);
    $org = Organization::factory()->create(['slug' => 'mine']);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name, 'slug' => 'taken', 'notification_cadence' => cadenceOf($org),
    ])->assertSessionHasErrors('slug');
});

/**
 * The other half of App\Rules\UnclaimedSlug's seam. StoreOrganizationRequest guards the
 * create path (byRegistry()); without the same rule here, an organization slug could be
 * edited back onto a registry slug it already collides with — and the legacy /r/{slug}
 * redirect would then start resolving to the wrong registry, silently, exactly as the
 * matching test on the registry-slug update path (GroupSlugEditTest.php) proves for the
 * other direction.
 */
it('refuses an organization slug a registry already answers to', function () {
    Group::factory()->create(['slug' => 'acme']);

    $org = Organization::factory()->create(['slug' => 'mine']);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name, 'slug' => 'acme', 'notification_cadence' => cadenceOf($org),
    ])->assertSessionHasErrors('slug');

    expect($org->fresh()->slug)->toBe('mine');
});

/**
 * `notification_cadence` has a DB default ('hourly'), not a factory one — a freshly created
 * model instance does not carry it until reloaded (the UUID-model DB-default gotcha this
 * codebase already knows about), so a PUT that echoes `$org->notification_cadence` straight
 * back would submit null and get refused by the request's own `required` rule.
 */
function cadenceOf(Organization $org): string
{
    return $org->refresh()->notification_cadence;
}

/**
 * The confirmation dialog cannot show a single before/after URL the way the registry-slug
 * edit does (admin/groups/Show.vue) — one organization slug prefixes every registry this
 * organization owns, for potentially many different registry slugs, and some of those
 * registries may sit on a custom domain where the slug never appears at all. So instead of
 * a URL, the console gets the bare path *pattern* with both slugs left open and substitutes
 * only the organization segment locally — never assembling a /r/... path of its own. This
 * pins that the Show page actually hands over that pattern (and the registries_count the
 * dialog states alongside it), stated against RegistryUrl rather than a literal so the URL
 * form stays declared in one place.
 */
it('hands the console the bare url pattern and the affected registry count', function () {
    $org = Organization::factory()->create(['slug' => 'kunde']);
    Group::factory()->for($org)->create();
    Group::factory()->for($org)->create();

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registryUrlTemplate', app(RegistryUrl::class)->template())
            ->where('organization.registries_count', 2));
});

/**
 * The organization slug is the first segment of the customer portal's address as well, so a
 * rename breaks a saved /c/... link. It breaks the /r/... addresses too — the first case in
 * this file asserts that /r/{old-org}/... answers 404, because `groups.legacy_slug` freezes
 * only the ONE-SEGMENT pre-upgrade address and there is no organization-level equivalent.
 * The portal is therefore not the milder case and must not be described as one; it is the
 * case where the people holding the link are the ones nobody has a list of. The
 * confirmation has to name the address, and this pins the payload it substitutes into.
 *
 * Two assertions, because either alone would prove nothing. The expect() pins the address
 * FORM against the route the application actually answers on, so a change to the /c/ prefix
 * reddens a test rather than quietly rewording a dialog. The assertInertia() pins the WIRING
 * the way the registry pattern above is pinned — stated against PortalUrl rather than a
 * literal, so the payload and the form cannot be made to disagree by editing one of them.
 */
it('hands the console the portal address form the slug change moves', function () {
    $org = Organization::factory()->create(['slug' => 'kunde']);

    expect(app(PortalUrl::class)->template())->toBe('/c/{organization}')
        ->and(app(PortalUrl::class)->pathFor($org->slug))->toBe('/c/kunde');

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('portalPathTemplate', app(PortalUrl::class)->template()));
});
