<?php

// A slug that validation accepts must be a slug a Docker client can actually address.
//
// Under path addressing (App\Http\Middleware\ResolveOciContext) the organization and registry
// slugs become the first two path components of an OCI repository name, and the OCI name
// grammar — routes/registry.php's `$ociName`, and every real client's own reference parser —
// admits a hyphen only BETWEEN alphanumerics. The old `regex:/^[a-z0-9-]+$/` admitted `acme-`
// and `-acme`, so `acme-/intern/meinapp` matched no `/v2` route at all and fell to the
// fallback's bare `{}` 404, which explains nothing. App\Rules\AddressableSlug is the writing
// end of that agreement; these cases are what keep the two patterns from drifting apart.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Rules\AddressableSlug;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('refuses a new organization slug that ends in a hyphen', function () {
    $this->actingAs($this->admin)
        ->post('/admin/organizations', ['name' => 'Acme GmbH', 'slug' => 'acme-'])
        ->assertSessionHasErrors(['slug' => AddressableSlug::HYPHEN_AT_EDGE]);

    expect(Organization::where('slug', 'acme-')->exists())->toBeFalse();
});

it('refuses a new organization slug that starts with a hyphen', function () {
    $this->actingAs($this->admin)
        ->post('/admin/organizations', ['name' => 'Acme GmbH', 'slug' => '-acme'])
        ->assertSessionHasErrors('slug');

    expect(Organization::where('slug', '-acme')->exists())->toBeFalse();
});

it('refuses a new registry slug that ends in a hyphen', function () {
    $this->actingAs($this->admin)
        ->post('/admin/groups', ['name' => 'Intern', 'slug' => 'intern-'])
        ->assertSessionHasErrors(['slug' => AddressableSlug::HYPHEN_AT_EDGE]);

    expect(Group::where('slug', 'intern-')->exists())->toBeFalse();
});

it('still allows hyphens inside a slug, consecutive ones included', function () {
    // The counterpart, so the refusals above cannot be satisfied by rejecting every hyphen:
    // `$ociName`'s `-+` matches a run of them between alphanumerics, so `a--b` is a perfectly
    // addressable repository path component and must keep validating.
    $this->actingAs($this->admin)
        ->post('/admin/organizations', ['name' => 'Acme GmbH', 'slug' => 'acme--gmbh'])
        ->assertSessionHasNoErrors();

    expect(Organization::where('slug', 'acme--gmbh')->exists())->toBeTrue();
});

it('refuses renaming a registry INTO an unaddressable slug', function () {
    // The loophole a create-only rule would leave: a slug that passed on creation could
    // simply be edited into an unaddressable one the next day.
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Intern', 'slug' => 'intern-', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasErrors('slug');

    expect($group->fresh()->slug)->toBe('intern');
});

it('still lets a registry that already holds an unaddressable slug be saved unchanged', function () {
    // Existing slugs are deliberately not migrated or rewritten, so the rule must not lock an
    // instance out of editing rows that predate it: the row's CURRENT slug is exempt (see
    // AddressableSlug's `$unchanged`). Only a NEW value has to be addressable — this saves a
    // different NAME while leaving the address alone, and must succeed.
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['slug' => 'alt-', 'name' => 'Alt']);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Neuer Name', 'slug' => 'alt-', 'public' => false, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->name)->toBe('Neuer Name')
        ->and($group->fresh()->slug)->toBe('alt-');
});

it('still lets an organization that already holds an unaddressable slug be saved unchanged', function () {
    $org = Organization::factory()->create(['slug' => 'acme-']);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => 'Acme AG',
        'slug' => 'acme-',
        // `notification_cadence` has a DB default, not a factory one, so a fresh instance
        // does not carry it until reloaded — refresh() first or the PUT submits null and is
        // refused by the request's own `required` rule, which would hide what this asserts.
        'notification_cadence' => $org->refresh()->notification_cadence,
    ])->assertSessionHasNoErrors();

    expect($org->fresh()->name)->toBe('Acme AG');
});

// The setup wizard is the third writer of a registry slug and the only one outside /admin;
// its case lives in tests/Feature/SetupWizardTest.php, beside the payload helper it needs.
