<?php

use App\Enums\GitProvider;
use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * A stored git token must only ever be transmitted to the host the credential was
 * created for. Without that binding an operator can point any credential at a host they
 * control and read the cleartext token out of its access log.
 */
function hostBindingAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('refuses to verify a credential against a host it is not bound to', function () {
    Process::fake();
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub]);

    $this->actingAs(hostBindingAdmin($org))->postJson("/admin/git-credentials/{$cred->id}/test", [
        'repository_url' => 'https://evil.test/acme/private.git',
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');

    Process::assertNothingRan();
});

it('verifies a credential against the host it is bound to', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub]);

    $this->actingAs(hostBindingAdmin($org))->postJson("/admin/git-credentials/{$cred->id}/test", [
        'repository_url' => 'https://github.com/acme/private.git',
    ])->assertOk()->assertJsonPath('ok', true);
});

it('refuses to probe with a credential bound to another host', function () {
    Process::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub]);

    $this->actingAs(hostBindingAdmin($org))->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://evil.test/acme/private.git',
        'git_credential_id' => $cred->id,
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');

    Process::assertNothingRan();
});

it('rejects creating a package whose repository host the credential is not bound to', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub]);

    $this->actingAs(hostBindingAdmin($org))->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/lib',
        'repository_url' => 'https://evil.test/acme/lib.git',
        'git_credential_id' => $cred->id,
    ])->assertSessionHasErrors('repository_url');

    expect(Package::where('name', 'acme/lib')->exists())->toBeFalse();
});

it('rejects retargeting a package repository to a host the credential is not bound to', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $admin = hostBindingAdmin($org);
    $group = Group::factory()->for($org)->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/lib',
        'repository_url' => 'https://github.com/acme/lib.git',
        'git_credential_id' => $cred->id,
        'group_ids' => [$group->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $package = Package::where('name', 'acme/lib')->firstOrFail();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'https://evil.test/acme/lib.git',
        'git_credential_id' => $cred->id,
    ])->assertSessionHasErrors('repository_url');

    expect($package->fresh()->repository_url)->toBe('https://github.com/acme/lib.git');
});

it('withholds the credential token when the stored repository host no longer matches', function () {
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'token' => 'ghp_secret']);
    $package = Package::factory()->create([
        'repository_url' => 'https://evil.test/acme/lib.git',
        'git_credential_id' => $cred->id,
    ]);

    expect($package->gitAuth()['token'])->toBeNull();
});

it('requires an explicit host for a generic credential and binds it to that host', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $org = Organization::factory()->create();
    $admin = hostBindingAdmin($org);

    $this->actingAs($admin)->post('/admin/git-credentials', [
        'name' => 'Self hosted', 'organization_id' => $org->id, 'provider' => 'generic', 'token' => 'tok',
    ])->assertSessionHasErrors('host');

    $this->actingAs($admin)->post('/admin/git-credentials', [
        'name' => 'Self hosted', 'organization_id' => $org->id, 'provider' => 'generic',
        'host' => 'git.example.test', 'token' => 'tok',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cred = GitCredential::firstOrFail();

    $this->actingAs($admin)->postJson("/admin/git-credentials/{$cred->id}/test", [
        'repository_url' => 'https://git.example.test/acme/private.git',
    ])->assertOk()->assertJsonPath('ok', true);

    $this->actingAs($admin)->postJson("/admin/git-credentials/{$cred->id}/test", [
        'repository_url' => 'https://evil.test/acme/private.git',
    ])->assertStatus(422);
});

it('refuses to retarget a package that keeps its stored inline repository token', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $admin = hostBindingAdmin($org);
    $group = Group::factory()->for($org)->create();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/lib',
        'repository_url' => 'https://github.com/acme/lib.git',
        'repository_token' => 'ghp_inline',
        'group_ids' => [$group->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $package = Package::where('name', 'acme/lib')->firstOrFail();

    // Moving the repository to another host while silently keeping the stored token
    // would ship that token to the new host on the next sync.
    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'https://evil.test/acme/lib.git',
    ])->assertSessionHasErrors('repository_url');

    expect($package->fresh()->repository_url)->toBe('https://github.com/acme/lib.git');

    // Explicitly dropping the token makes the move legitimate.
    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'https://evil.test/acme/lib.git',
        'remove_token' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($package->fresh()->repository_token)->toBeNull()
        ->and($package->fresh()->repository_url)->toBe('https://evil.test/acme/lib.git');
});
