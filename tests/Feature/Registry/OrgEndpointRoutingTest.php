<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The German copy is pinned verbatim by the org-level-registry spec — any drift here is a
// spec violation, not a wording preference.
const ORG_WRITE_DENIED_MESSAGE = 'Veröffentlichen ist nur je Registry möglich — nutzt die Registry-eigene Adresse.';

it('resolves an organization by slug and sets registryOrganization, leaving registryGroup null', function () {
    // A lightweight probe route rather than a real registry endpoint: the read controllers
    // (ComposerController, NpmController, ...) only know `registryGroup` until Task 3+ wires
    // them to `registryOrganization`, so asserting a real endpoint's happy path here would
    // couple this routing test to controller work that isn't in scope yet. This mirrors how
    // registry.auth's own attribute is tested (see RegistryAuthTest) — a throwaway route
    // under the same middleware, inspecting request attributes directly.
    Route::prefix('/o/{orgSlug}')->middleware(['registry.context', 'registry.auth'])
        ->get('/_test/probe', function (Request $request) {
            return response()->json([
                'organization_slug' => $request->attributes->get('registryOrganization')?->slug,
                'group' => $request->attributes->get('registryGroup'),
            ]);
        });

    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->getJson('/o/acme/_test/probe')
        ->assertOk()
        ->assertJsonPath('organization_slug', 'acme')
        ->assertJsonPath('group', null);
});

it('404s an unknown organization slug under /o/', function () {
    $this->getJson('/o/does-not-exist/packages.json')->assertNotFound();
});

it('keeps /o/ working when organizations.portal_enabled is false — this is the registry API, not the portal', function () {
    Route::prefix('/o/{orgSlug}')->middleware(['registry.context', 'registry.auth'])
        ->get('/_test/probe', function (Request $request) {
            return response()->json([
                'organization_slug' => $request->attributes->get('registryOrganization')?->slug,
            ]);
        });

    $org = Organization::factory()->create(['slug' => 'acme', 'portal_enabled' => false]);

    $this->getJson('/o/acme/_test/probe')
        ->assertOk()
        ->assertJsonPath('organization_slug', 'acme');
});

it('still serves the slug-access registry unchanged after the /o/ mount was added', function () {
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $group->packages()->attach($package);
    $org = $group->organization;

    $this->getJson("/r/{$org->slug}/{$group->slug}/packages.json")->assertOk();
});

it('still serves custom-domain access unchanged after the /o/ mount was added', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/packages.json')
        ->assertOk();
});

it('answers 405 with the German message for npm publish under /o/', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->putJson("/o/{$org->slug}/leftpad", ['name' => 'leftpad'])
        ->assertStatus(405)
        ->assertJsonPath('message', ORG_WRITE_DENIED_MESSAGE);
});

it('answers 405 with the German message for scoped npm publish under /o/', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->putJson("/o/{$org->slug}/@acme/widget", ['name' => '@acme/widget'])
        ->assertStatus(405)
        ->assertJsonPath('message', ORG_WRITE_DENIED_MESSAGE);
});

it('answers 405 with the German message for pypi upload under /o/', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->postJson("/o/{$org->slug}/", ['name' => 'demo'])
        ->assertStatus(405)
        ->assertJsonPath('message', ORG_WRITE_DENIED_MESSAGE);
});
