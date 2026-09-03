<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Registry\RegistryUrl;

it('changes a registry slug and with it the registry url', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'old', 'name' => 'Alt']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Alt',
        'slug' => 'new',
        'public' => false,
        'portal_enabled' => false,
    ])->assertRedirect();

    expect($group->fresh()->slug)->toBe('new');
    // The registry now answers at the new address — and, being private, answers 401 rather
    // than the 404 an unknown registry gets. (The task brief predicted 404 here; the
    // registry surface distinguishes "needs a token" from "no such registry", see
    // ComposerMetadataTest, and the whole point of this line is that the URL now resolves.)
    $this->get("/r/{$org->slug}/new/packages.json")->assertUnauthorized();
    $this->get("/r/{$org->slug}/old/packages.json")->assertNotFound();
});

it('refuses a slug already used inside the same organization', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    Group::factory()->for($org)->create(['slug' => 'taken']);
    $group = Group::factory()->for($org)->create(['slug' => 'mine']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Mine', 'slug' => 'taken', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasErrors('slug');
});

it('allows a slug another organization already uses', function () {
    Group::factory()->create(['slug' => 'packages']);

    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'mine']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Mine', 'slug' => 'packages', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();
});

it('keeps its own slug available when nothing else about it changes', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'mine', 'name' => 'Alt']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Neu', 'slug' => 'mine', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->name)->toBe('Neu');
});

/**
 * The other half of App\Rules\UnclaimedSlug's seam: the rule sat on the create paths only,
 * so without it here the collision it exists to prevent could simply be edited back in the
 * next day — and the legacy /r/{slug} redirect would then start answering with the wrong
 * registry, silently.
 */
it('refuses a slug an organization already answers to', function () {
    Organization::factory()->create(['slug' => 'kadenz']);

    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'mine']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Mine', 'slug' => 'kadenz', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasErrors('slug');

    expect($group->fresh()->slug)->toBe('mine');
});

it('serves the registry under its new url and no longer under the old one', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'old', 'public' => true]);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => $group->name, 'slug' => 'neu', 'public' => true, 'portal_enabled' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(registryPath($group->fresh()).'/packages.json')->assertOk();
    $this->get("/r/{$org->slug}/old/packages.json")->assertNotFound();
});

it('hands the console the registry url instead of letting it assemble one', function () {
    $org = Organization::factory()->create(['slug' => 'kunde']);
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'acme']);

    $this->actingAs($admin)->get(route('admin.groups.show', $group))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('group.url_path', '/r/kunde/acme')
            ->where('group.url', app(RegistryUrl::class)->canonical($group))
            // The pattern the confirmation dialog substitutes the new slug into — the URL
            // form is stated in PHP, never rebuilt in Vue.
            ->where('group.url_pattern', app(RegistryUrl::class)->origin().'/r/kunde/{registry}'));
});

/**
 * A column-restricted eager load that omits the organization's slug yields null rather than
 * an error (Eloquent strict mode is off repo-wide), so a payload built from it would ship
 * /r//{groupSlug} with the whole suite green. Every list that renders a registry URL is
 * pinned here against exactly that.
 */
it('resolves the organization segment in every list that renders a registry url', function () {
    $org = Organization::factory()->create(['slug' => 'kunde', 'is_operator' => true]);
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'acme', 'name' => 'Acme Registry']);
    $package = Package::factory()->inOrgOf($group)->create(['name' => 'acme/lib']);
    $group->packages()->attach($package->id);

    $this->actingAs($admin)->get(route('admin.groups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.url_path', '/r/kunde/acme')
            // Not decoration: the create sheet substitutes into this at setup time, so a
            // payload that stops carrying it leaves the whole registry list white with a
            // TypeError on `undefined.replace()` — invisible to the PHP suite, to
            // check:props (which counts declared props, not delivered ones) and to vue-tsc.
            // Asserted against RegistryUrl rather than a literal so the URL form stays
            // stated in one place.
            ->where('registryUrlTemplate', app(RegistryUrl::class)->template()));

    $this->actingAs($admin)->get(route('admin.packages.show', $package))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('groups.0.url_path', '/r/kunde/acme'));

    $this->actingAs($admin)->getJson('/admin/search?q=Acme')
        ->assertOk()
        ->assertJsonPath('registries.0.url_path', '/r/kunde/acme');
});
