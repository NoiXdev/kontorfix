<?php

/*
 * Which setup instructions a registry page offers, and what the Docker ones say.
 *
 * The defect these cases exist for: both pages that render RegistrySetup.vue derived the
 * client list from the packages already in the registry — `[...new Set(packages.map(p =>
 * p.type))]` — so a registry with no packages showed no instructions at all. That is the
 * state EVERY registry is in on the day it is created, and for images it is the state it
 * stays in, because nobody pushes a first image into a registry whose address is written
 * nowhere. The list comes from RegistryTypeService::effectiveFor() now: what the
 * organization MAY serve.
 *
 * Every case here asserts the Inertia PROP, never rendered HTML. The wording itself is not
 * in PHP at all — it lives in resources/js/components/kontorfix/dockerSetup.ts, where
 * dockerSetup.test.ts pins each string whole. What the server owes these pages is the
 * facts, and the facts are what is asserted.
 */

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
});

// --- The client list comes from what the registry MAY serve ---

it('offers every enabled type on a customer registry that holds no packages at all', function () {
    // The case the whole task exists for. Zero packages, and all four ecosystems still
    // listed — under the old computed this prop was `[]` and the Einrichtung tab was blank.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Registry')
            ->has('packages', 0)
            ->where('types', ['composer', 'npm', 'python', 'docker'])
            ->etc());
});

it('offers every enabled type on an operator registry that holds no packages at all', function () {
    // admin/groups/Show.vue had the identical computed and therefore the identical defect.
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for(Organization::factory())->create();

    $this->actingAs($admin)->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/groups/Show')
            ->has('packages', 0)
            ->where('types', ['composer', 'npm', 'python', 'docker'])
            ->etc());
});

it('does not offer a type the organization has switched off, however many packages of it exist', function () {
    // The reversal. A prop hardcoded to all four types would satisfy both cases above, and
    // would offer a customer a Docker snippet against a registry whose OCI endpoints answer
    // 404 (EnsureRegistryTypeEnabled). The package below also proves the list is no longer
    // derived from the contents: docker is present in the registry and absent from the list.
    $org = Organization::factory()->create([
        'slug' => 'acme',
        'enabled_registry_types' => ['composer', 'npm'],
    ]);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('types', ['composer', 'npm'])->etc());
});

it('does not offer a type the instance has switched off, even when the organization allows it', function () {
    // The instance-wide ceiling, which effectiveFor() intersects against. A portal that
    // read only the organization's own column would hand out instructions for an ecosystem
    // this installation does not serve at all.
    SystemSetting::current()->update(['enabled_registry_types' => ['composer', 'docker']]);
    $org = Organization::factory()->create([
        'slug' => 'acme',
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('types', ['composer', 'docker'])->etc());
});

it('sends an empty list for an organization that may serve nothing at all', function () {
    // The boundary the two pages have to be able to render. `enabled_registry_types = []`
    // is accepted by Admin\OrganizationController's `['present', 'array']` rule and stored,
    // and effectiveFor() intersects the instance ceiling with it down to nothing — so this
    // prop really can arrive empty, and every registry endpoint then answers 404
    // (EnsureRegistryTypeEnabled). Pinned here so the server keeps SAYING so: while
    // RegistrySetup.vue substituted a three-ecosystem default for an empty list, a page
    // built on this prop offered Composer, npm and pip instructions against a registry
    // that serves neither.
    $org = Organization::factory()->create([
        'slug' => 'acme',
        'enabled_registry_types' => [],
    ]);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Registry')
            ->where('types', [])
            ->etc());
});

// --- The Docker address, on a registry with no custom domain ---

it('gives a customer registry without a domain a working docker address on the instance host', function () {
    // Plate 3, rewritten. The old empty state told this customer that images were
    // impossible here; ResolveOciContext made that false, and these three fields are the
    // whole of what the page needs to say the true thing instead. `dockerHasDomain` false
    // is what selects the note about a hostname being the SHORTER address — never the
    // price of entry.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Registry')
            ->where('snippets.dockerHost', 'reg.example.test')
            // The namespace ResolveOciContext strips back off: `{org}/{registry}/`, with no
            // leading slash and no `/r` — those belong to the Composer/npm/Python address,
            // not to an image reference.
            ->where('snippets.dockerRepositoryPrefix', 'acme/intern/')
            ->where('snippets.dockerHasDomain', false)
            ->etc());
});

it('gives a customer registry with a domain the short address and no namespace', function () {
    // The reversal: a constant `acme/intern/` prefix would push images into a repository
    // called `acme/intern/meinapp` on the custom domain, which is a different repository
    // from the one the registry serves.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    Domain::factory()->for($group)->create(['hostname' => 'images.acme.test']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Registry')
            ->where('snippets.dockerHost', 'images.acme.test')
            ->where('snippets.dockerRepositoryPrefix', '')
            ->where('snippets.dockerHasDomain', true)
            ->etc());
});

// --- The package page's install hint follows the same address ---

it('gives a customer the real docker pull command for a package in a registry without a domain', function () {
    // Step 5's other false statement: the hint was `docker pull <registry-host>/meinapp`
    // for every registry without a domain — a placeholder handed to a customer whose
    // registry was in fact perfectly pullable.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Package')
            ->where('install', 'docker pull reg.example.test/acme/intern/meinapp')
            ->etc());
});

it('gives a customer the short docker pull command once the registry has a domain', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    Domain::factory()->for($group)->create(['hostname' => 'images.acme.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/Package')
            ->where('install', 'docker pull images.acme.test/meinapp')
            ->etc());
});
