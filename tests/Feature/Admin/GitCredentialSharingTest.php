<?php

use App\Enums\GitProvider;
use App\Enums\SyncStatus;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

/**
 * Task 6: shared git credentials. `GitCredential::isUsableBy()` / `scopeUsableBy()` and the
 * schema underneath (is_global, git_credential_organization) are Task 1's and already
 * covered by tests/Feature/Oci/RetentionScopesSchemaTest.php. This file covers what Task 6
 * builds on top: the operator UI's sharing controls, the package dropdown honouring the
 * usable set, and — the design's binding decision — that usability is re-checked at SYNC
 * time, so un-sharing ends a package's access on its very next sync rather than only in the
 * next dropdown it opens.
 */
function sharingCustomer(): Organization
{
    return Organization::factory()->create(['is_operator' => false]);
}

it('lets a customer package resolve git auth through a global operator credential', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create([
        'provider' => GitProvider::GitLab, 'token' => 'glpat-global', 'is_global' => true,
    ]);

    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://gitlab.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    $auth = $package->gitAuth();
    expect($auth['token'])->toBe('glpat-global')
        ->and($auth['provider'])->toBe(GitProvider::GitLab);
});

it('lets a customer package resolve git auth through a credential explicitly shared to it', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create([
        'provider' => GitProvider::GitLab, 'token' => 'glpat-shared',
    ]);
    $cred->sharedOrganizations()->attach($customer);

    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://gitlab.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    expect($package->gitAuth()['token'])->toBe('glpat-shared');
});

it('does not resolve git auth for an org the credential was never shared to', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $stranger = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['token' => 'ghp_x']);
    $cred->sharedOrganizations()->attach($stranger);

    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://github.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    expect($package->gitAuth()['token'])->toBeNull();
});

it('refuses a credential on the very next sync once it has been un-shared', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['token' => 'ghp_x']);
    $cred->sharedOrganizations()->attach($customer);

    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://github.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    // Still shared at this point: the grant is live.
    expect($package->gitAuth()['token'])->toBe('ghp_x');

    $cred->sharedOrganizations()->detach($customer);

    // SyncPackage's own preflight check refuses before ever reaching the repository — a
    // deterministic configuration error, not an incidental git authentication failure, and
    // never the unhandled exception (500) the design explicitly rules out.
    (new SyncPackage($package->fresh()))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->not->toBeEmpty()
        ->and($package->fresh()->gitAuth()['token'])->toBeNull();
});

it('refuses a credential on the next sync once a global flag is revoked', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['token' => 'ghp_y', 'is_global' => true]);

    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://github.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    expect($package->gitAuth()['token'])->toBe('ghp_y');

    $cred->update(['is_global' => false]);

    (new SyncPackage($package->fresh()))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->not->toBeEmpty();
});

it('withholds the token directly once usability is revoked, independent of SyncPackage', function () {
    // Defense in depth: Package::gitAuth() refuses the same credential on its own, for any
    // caller that reaches it without going through SyncPackage's preflight.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['token' => 'ghp_z', 'is_global' => true]);
    $package = Package::factory()->for($customer)->create([
        'repository_url' => 'https://github.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    $cred->update(['is_global' => false]);

    expect($package->fresh()->gitAuth()['token'])->toBeNull();
});

it('never exposes the token to a non-owner, on the index listing or either package dropdown', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['is_global' => true, 'token' => 'super-secret-token']);
    $customerAdmin = adminOf($customer);

    $this->actingAs($customerAdmin)->get('/admin/git-credentials')
        ->assertInertia(fn ($page) => $page
            ->has('credentials', 1)
            ->where('credentials.0.id', $cred->id)
            ->where('credentials.0.is_own', false)
            ->where('credentials.0.badge', 'global')
            ->missing('credentials.0.token'));

    $this->actingAs($customerAdmin)->get('/admin/packages/create')
        ->assertInertia(fn ($page) => $page
            ->has('gitCredentials', 1)
            ->where('gitCredentials.0.id', $cred->id)
            ->missing('gitCredentials.0.token'));

    // The edit/show page's dropdown (PackageController::show()) is a second, separate
    // non-owner-facing payload — GitCredential::usableBy($package->organization), not
    // gitCredentialOptions() — and needs its own assertion rather than relying on the
    // create page's coverage to stand in for it.
    $package = Package::factory()->for($customer)->create(['repository_url' => 'https://github.com/acme/lib.git']);
    $this->actingAs($customerAdmin)->get("/admin/packages/{$package->id}")
        ->assertInertia(fn ($page) => $page
            ->has('gitCredentials', 1)
            ->where('gitCredentials.0.id', $cred->id)
            ->missing('gitCredentials.0.token'));
});

it('does not list a foreign, non-shared credential at all', function () {
    $orgA = sharingCustomer();
    $orgB = sharingCustomer();
    GitCredential::factory()->for($orgB)->create();

    $this->actingAs(adminOf($orgA))->get('/admin/git-credentials')
        ->assertInertia(fn ($page) => $page->has('credentials', 0));
});

it('forbids a customer from sharing, editing or deleting a foreign credential', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create();

    $customerAdmin = adminOf($customer);

    $this->actingAs($customerAdmin)->put("/admin/git-credentials/{$cred->id}", [
        'name' => 'hijacked', 'provider' => 'github', 'is_global' => true,
    ])->assertForbidden();

    $this->actingAs($customerAdmin)->delete("/admin/git-credentials/{$cred->id}")->assertForbidden();

    expect($cred->fresh())->not->toBeNull()
        ->and($cred->fresh()->name)->not->toBe('hijacked')
        ->and($cred->fresh()->is_global)->toBeFalse();
});

it('ignores is_global and sharing submitted for a customer-owned credential', function () {
    $customer = sharingCustomer();
    $other = sharingCustomer();
    $admin = adminOf($customer);

    $this->actingAs($admin)->post('/admin/git-credentials', [
        'name' => 'mine', 'organization_id' => $customer->id, 'provider' => 'github', 'token' => 'tok',
        'is_global' => true, 'shared_organization_ids' => [$other->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cred = GitCredential::where('name', 'mine')->firstOrFail();
    expect($cred->is_global)->toBeFalse()
        ->and($cred->sharedOrganizations()->count())->toBe(0);

    $this->actingAs($admin)->put("/admin/git-credentials/{$cred->id}", [
        'name' => 'mine', 'provider' => 'github', 'is_global' => true, 'shared_organization_ids' => [$other->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($cred->fresh()->is_global)->toBeFalse()
        ->and($cred->fresh()->sharedOrganizations()->count())->toBe(0);
});

it('lets an operator organization credential be made global or shared, deduplicated', function () {
    $customerA = sharingCustomer();
    $customerB = sharingCustomer();
    // A Maintainer (not Admin) of the operator organization: unlike an operator-org Admin,
    // this role never trips User::isSuperAdmin()'s grandfather clause, so the assertions
    // below exercise the is_operator gate itself rather than a super-admin short-circuit.
    $admin = operatorMaintainer();
    $operator = $admin->organization;

    $this->actingAs($admin)->post('/admin/git-credentials', [
        'name' => 'shared cred', 'organization_id' => $operator->id, 'provider' => 'github', 'token' => 'tok',
        // A duplicate id on purpose — the pivot is unique (git_credential_id,
        // organization_id), so the controller must dedupe before syncing rather than
        // rely on the database to reject the resubmission.
        'shared_organization_ids' => [$customerA->id, $customerB->id, $customerA->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cred = GitCredential::where('name', 'shared cred')->firstOrFail();
    expect($cred->is_global)->toBeFalse();
    $sharedIds = $cred->sharedOrganizations()->pluck('organizations.id')->sort()->values()->all();
    expect($sharedIds)->toBe(collect([$customerA->id, $customerB->id])->sort()->values()->all());

    // The owner's own index row reflects the share too, not only the pivot table.
    $this->actingAs($admin)->get('/admin/git-credentials')
        ->assertInertia(fn ($page) => $page
            ->has('credentials', 1)
            ->where('credentials.0.is_own', true)
            ->where('credentials.0.badge', 'shared'));

    $this->actingAs($admin)->put("/admin/git-credentials/{$cred->id}", [
        'name' => 'shared cred', 'provider' => 'github', 'is_global' => true, 'shared_organization_ids' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($cred->fresh()->is_global)->toBeTrue()
        ->and($cred->fresh()->sharedOrganizations()->count())->toBe(0);

    $this->actingAs($admin)->get('/admin/git-credentials')
        ->assertInertia(fn ($page) => $page->where('credentials.0.badge', 'global'));
});

it('assigns an operator credential to a customer package via the create form', function () {
    Queue::fake();
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = sharingCustomer();
    $cred = GitCredential::factory()->for($operator)->create(['is_global' => true, 'token' => 'ghp_assign']);

    $admin = adminOf($customer);
    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer', 'name' => 'acme/shared-lib',
        'repository_url' => 'https://github.com/acme/shared-lib.git',
        'git_credential_id' => $cred->id,
        'group_ids' => [homeRegistryId($admin)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $package = Package::where('name', 'acme/shared-lib')->firstOrFail();
    expect($package->git_credential_id)->toBe($cred->id)
        ->and($package->gitAuth()['token'])->toBe('ghp_assign');
});

/**
 * Drift guard for GitCredential::scopeUsableByAny(), the multi-organization query behind
 * GitCredentialController::index()'s "foreign but usable" listing,
 * PackageController::gitCredentialOptions() and PackageController::assertCredentialUsableInScope().
 * Before it existed, all three hand-rolled their own copy of the own/global/shared OR — a
 * security-sensitive boundary with three independent chances to drift from
 * GitCredential::isUsableBy() and from each other. This asserts the extracted scope agrees
 * with isUsableBy() across an owner/global/shared/unrelated fixture matrix, the same way
 * scopeUsableBy()'s own drift test (RetentionScopesSchemaTest) does for a single
 * organization.
 */
it('agrees with isUsableBy() across every organization in a multi-organization scope', function () {
    $owner = Organization::factory()->create();
    $scopedA = Organization::factory()->create();
    $scopedB = Organization::factory()->create();
    $unrelated = Organization::factory()->create();
    $scopedOrgIds = [$scopedA->id, $scopedB->id];

    $own = GitCredential::factory()->for($scopedA)->create();
    $global = GitCredential::factory()->for($owner)->create(['is_global' => true]);
    $shared = GitCredential::factory()->for($owner)->create();
    $shared->sharedOrganizations()->attach($scopedB);
    $sharedElsewhere = GitCredential::factory()->for($owner)->create();
    $sharedElsewhere->sharedOrganizations()->attach($unrelated);
    $foreign = GitCredential::factory()->for($owner)->create();

    $usableIds = GitCredential::query()->usableByAny($scopedOrgIds)->pluck('id')->all();

    foreach ([$own, $global, $shared, $sharedElsewhere, $foreign] as $credential) {
        // "Usable by any of the scoped organizations" must mean exactly what it would mean
        // to ask isUsableBy() once per organization and OR the answers — never more, never
        // less.
        $expected = collect([$scopedA, $scopedB])->contains(fn (Organization $org) => $credential->isUsableBy($org));

        expect(in_array($credential->id, $usableIds, true))->toBe($expected, "credential {$credential->name} disagreed");
    }

    expect($usableIds)->toContain($own->id, $global->id, $shared->id)
        ->and($usableIds)->not->toContain($sharedElsewhere->id, $foreign->id);
});

it('answers usableByAny([]) with nothing, not every global credential', function () {
    // "Usable by any organization in an empty set" is empty. Before the guard, `orWhere
    // ('is_global', true)` matched regardless of the (empty, always-false) `whereIn`, so a
    // caller with no candidate owners yet saw every global credential offered.
    GitCredential::factory()->create(['is_global' => true]);

    expect(GitCredential::query()->usableByAny([])->pluck('id')->all())->toBe([]);
});
