<?php

use App\Enums\GitProvider;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Creates a package owned by $org (attached to one of its groups) and returns it,
 * going through the store endpoint so the group/org wiring matches production.
 */
function ownedPackage(User $admin, Organization $org, array $overrides = []): Package
{
    Queue::fake();

    $group = Group::factory()->for($org)->create();

    test()->actingAs($admin)->post('/admin/packages', array_merge([
        'type' => 'composer',
        'name' => 'acme/widget',
        'repository_url' => 'https://github.com/acme/widget.git',
        'group_ids' => [$group->id],
    ], $overrides))->assertRedirect()->assertSessionHasNoErrors();

    return Package::where('name', $overrides['name'] ?? 'acme/widget')->firstOrFail();
}

function updateAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('updates the repository url and re-syncs git-based packages', function () {
    $org = Organization::factory()->create();
    $admin = updateAdmin($org);
    $package = ownedPackage($admin, $org);

    Queue::fake();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'https://github.com/acme/renamed.git',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($package->fresh()->repository_url)->toBe('https://github.com/acme/renamed.git');
    Queue::assertPushed(SyncPackage::class);
});

it('stores an inline repository token encrypted and hidden, and removes it on request', function () {
    $org = Organization::factory()->create();
    $admin = updateAdmin($org);
    $package = ownedPackage($admin, $org);

    Queue::fake();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
        'repository_token' => 'ghp_inline',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $package->refresh();
    expect($package->repository_token)->toBe('ghp_inline')
        ->and(DB::table('packages')->where('id', $package->id)->value('repository_token'))->not->toBe('ghp_inline')
        ->and($package->toArray())->not->toHaveKey('repository_token');

    // Blank token + remove_token clears it.
    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
        'remove_token' => true,
    ])->assertRedirect();
    expect($package->fresh()->repository_token)->toBeNull();
});

it('assigns a git credential and clears it when omitted', function () {
    $org = Organization::factory()->create();
    $admin = updateAdmin($org);
    $package = ownedPackage($admin, $org);
    // The credential is bound to github.com, matching the package's repository host —
    // a credential for another host may not be assigned at all.
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'token' => 'ghp_x']);

    Queue::fake();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
        'git_credential_id' => $cred->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($package->fresh()->git_credential_id)->toBe($cred->id);

    // Omitting the credential clears the assignment.
    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
    ])->assertRedirect();
    expect($package->fresh()->git_credential_id)->toBeNull();
});

it('forbids assigning a foreign-org credential', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $admin = updateAdmin($orgA);
    $package = ownedPackage($admin, $orgA);
    $foreign = GitCredential::factory()->for($orgB)->create();

    Queue::fake();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();
});

it('does not re-sync publish-based packages on update', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $admin = updateAdmin($org);
    $group = Group::factory()->for($org)->create();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/pkg', 'group_ids' => [$group->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $package = Package::where('name', '@acme/pkg')->firstOrFail();

    Queue::fake();
    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_token' => 'ghp_x',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertNotPushed(SyncPackage::class);
});

it('rejects a non-https/ssh repository url', function () {
    $org = Organization::factory()->create();
    $admin = updateAdmin($org);
    $package = ownedPackage($admin, $org);

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'ftp://evil/repo.git',
    ])->assertSessionHasErrors('repository_url');
});

it('forbids updating a package outside the administered org', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = updateAdmin($orgA);
    $adminB = updateAdmin($orgB);
    $package = ownedPackage($adminB, $orgB);

    $this->actingAs($adminA)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'https://github.com/acme/hijack.git',
    ])->assertForbidden();
});
