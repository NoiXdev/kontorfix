<?php

use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;

it('renders the user create page for a super admin', function () {
    // operator() creates its own operator-org as a side effect, so the total is that org
    // plus Acme — not just Acme.
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    Organization::factory()->create(['name' => 'Acme']);

    $this->actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Create')
            // The select's options must reach the page. Without this the field renders
            // empty and nothing errors — the failure mode this whole task risks.
            ->has('organizations', 2));
});

it('refuses the user create page for a non-super user', function () {
    // An organization admin passes the `operator` gate (they administer their own org) but
    // not `super`. A plain member fails both gates, so it would not catch a route
    // accidentally dropped from the `super` group into `operator` — this fixture is what
    // makes that mutation observable.
    $orgAdmin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($orgAdmin)
        ->get(route('admin.users.create'))
        ->assertForbidden();
});

it('renders the user edit page with the record, not a listing row', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create(['name' => 'Acme']);
    $target = User::factory()->create(['name' => 'Zoe', 'organization_id' => $org->id]);

    $this->actingAs($admin)
        ->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Edit')
            ->where('user.name', 'Zoe')
            ->where('user.organization_id', $org->id)
            // Same operator()-side-effect accounting as the create-page test above.
            ->has('organizations', 2));
});

it('refuses the user edit page for a non-super user', function () {
    // Same reasoning as the create-page refusal test above: an org admin, not a plain member.
    $orgAdmin = User::factory()->create(['role' => UserRole::Admin]);
    $target = User::factory()->create();

    $this->actingAs($orgAdmin)
        ->get(route('admin.users.edit', $target))
        ->assertForbidden();
});

// --- Upstreams: ['auth', 'operator'], not ['auth', 'super'] — a plain org admin already
// suffices (see UpstreamController), unlike the users section above. Deliberately not
// `User::factory()->operator()`: that state's home organization has `is_operator: true`,
// which makes the user an effective super-admin (`User::isSuperAdmin()`) and would bypass
// the very per-organization scoping (`assertAdministersOrg`, `scopeGroupQuery`) these tests
// exist to exercise — the users-section tests above use it because that section actually
// sits behind `super`.

it('renders the upstream create page for a permitted operator', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Group::factory()->for($admin->organization)->create();

    $this->actingAs($admin)
        ->get(route('admin.upstreams.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/upstreams/Create')
            // The select's options must reach the page. Without this the field renders
            // empty and nothing errors — the failure mode this whole task risks.
            ->has('groups', 1));
});

it('refuses the upstream create page for a non-operator user', function () {
    $member = User::factory()->create(['role' => UserRole::Member]);

    $this->actingAs($member)
        ->get(route('admin.upstreams.create'))
        ->assertForbidden();
});

it('renders the upstream edit page with the record, not a listing row', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for($admin->organization)->create();
    $upstream = Upstream::factory()->create([
        'group_id' => $group->id,
        'url' => 'https://repo.packagist.org',
        'auth_token' => 'mirror-secret',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.upstreams.edit', $upstream))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/upstreams/Edit')
            ->where('upstream.url', 'https://repo.packagist.org')
            // Loaded from the record: `has_auth` isn't on any prior page's props for this
            // upstream, it's derived fresh from `auth_token` the same way the index is.
            ->where('upstream.has_auth', true)
            ->has('groups', 1));
});

it('refuses the upstream edit page for an admin of a different organization', function () {
    // A plain org admin passes the `operator` middleware (they administer their own org)
    // but not `assertAdministersOrg` for someone else's — the check `edit()` newly adds, so
    // the fixture has to actually depend on it (mirrors UpstreamCrudTest's identical
    // "forbids editing an upstream in a foreign organization" case for `update`).
    $foreignAdmin = User::factory()->create(['role' => UserRole::Admin]);
    $upstream = Upstream::factory()->create();

    $this->actingAs($foreignAdmin)
        ->get(route('admin.upstreams.edit', $upstream))
        ->assertForbidden();
});

// --- Git-credentials: same group, same reasoning as upstreams above.

it('renders the git-credential create page for a permitted operator', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.git-credentials.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/git-credentials/Create')
            // The select's options must reach the page. Without this the field renders
            // empty and nothing errors — the failure mode this whole task risks.
            ->has('organizations', 1)
            ->has('providers', 4));
});

it('refuses the git-credential create page for a non-operator user', function () {
    $member = User::factory()->create(['role' => UserRole::Member]);

    $this->actingAs($member)
        ->get(route('admin.git-credentials.create'))
        ->assertForbidden();
});

it('renders the git-credential edit page with the record, not a listing row', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $cred = GitCredential::factory()->for($admin->organization)->create(['name' => 'GH Deploy', 'token' => 'ghp_should_never_leave_server']);

    $this->actingAs($admin)
        ->get(route('admin.git-credentials.edit', $cred))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/git-credentials/Edit')
            ->where('credential.name', 'GH Deploy')
            ->has('organizations', 1)
            ->has('providers', 4));
});

it('refuses the git-credential edit page for an admin of a different organization', function () {
    $foreignAdmin = User::factory()->create(['role' => UserRole::Admin]);
    $cred = GitCredential::factory()->create();

    $this->actingAs($foreignAdmin)
        ->get(route('admin.git-credentials.edit', $cred))
        ->assertForbidden();
});

it('never sends the stored token back to the browser on the git-credential edit page', function () {
    // The credential's token is a secret, encrypted at rest and write-only from the UI
    // (see GitCredential::$hidden and GitCredentialController::edit()). The edit page must
    // not pre-fill it — its `token` field starts blank, matching the dialog it replaces.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $cred = GitCredential::factory()->for($admin->organization)->create(['token' => 'topsecret']);

    $this->actingAs($admin)
        ->get(route('admin.git-credentials.edit', $cred))
        ->assertInertia(fn ($page) => $page
            ->has('credential', fn ($c) => $c
                ->hasAll(['id', 'name', 'provider', 'host', 'username', 'organization_id'])
                ->missing('token')
                ->etc()
            ));
});
