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

it('structurally refuses every non-GET/HEAD route under /o/, not just the three this suite already knows about', function () {
    // A regression net for the failure mode the three tests above cannot catch by
    // themselves: they each hard-code one specific write URI, so a write route added to
    // the shared $registryEndpoints closure in routes/registry.php in the future — without
    // remembering the `$readOnly ? $orgWriteDenied : [...]` ternary — would silently ship
    // as a live write endpoint under /o/, and none of those three tests would fail, since
    // none of them would exercise the new route at all.
    //
    // This asserts against the ACTUAL registered route collection instead: every route
    // named under the 'registry.org.' prefix (see routes/registry.php's $named() helper —
    // every route the org mount registers gets one) whose method set is anything other than
    // GET/HEAD must resolve to a Closure action, not a controller action. `$orgWriteDenied`
    // is the only Closure ever registered in this closure; a real controller action is
    // always `[Controller::class, 'method']`. So a future write route that forgets the
    // ternary — and therefore keeps its controller action — fails this assertion instead of
    // silently shipping, without this test needing to know that route's URI in advance.
    // `collect(Route::getRoutes())` defeats PHPStan's template inference for
    // `collect()` (the RouteCollection is Traversable but not a generic type PHPStan can
    // read key/value types from) — routed through the collection's own `->getRoutes()`,
    // a plain `array`, instead.
    $orgRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'registry.org.'));

    // Fails loudly if route naming itself regresses (e.g. the 'registry.org.' prefix stops
    // being applied) rather than passing vacuously over an empty collection.
    expect($orgRoutes)->toHaveCount(16);

    $writeRoutes = $orgRoutes->filter(function ($route) {
        $readMethods = array_diff($route->methods(), ['HEAD']);

        return $readMethods !== ['GET'];
    });

    // Pins the three known writes so this test also fails if one of them ever stops being
    // treated as a write (e.g. a typo'd method).
    expect($writeRoutes->map(fn ($route) => $route->getName())->values()->all())->toEqualCanonicalizing([
        'registry.org.pypi.upload',
        'registry.org.npm.publish-scoped',
        'registry.org.npm.publish',
    ]);

    foreach ($writeRoutes as $route) {
        expect($route->getAction('uses'))
            ->toBeInstanceOf(Closure::class);
    }
});
