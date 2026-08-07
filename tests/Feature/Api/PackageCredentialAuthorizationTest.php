<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A stored git credential belongs to exactly one organization. Every path that accepts a
 * `git_credential_id` must prove the caller administers that organization — otherwise a
 * package can be created against a foreign tenant's credential and the sync ships that
 * tenant's decrypted token to whichever repository host the caller named.
 */
function credWriteKey(Organization $org): string
{
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    return $plain;
}

it('refuses to create a package referencing another organization\'s git credential', function () {
    Queue::fake();
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $foreign = GitCredential::factory()->for($theirs)->create(['token' => 'ghp_victim']);

    $this->withToken(credWriteKey($mine))->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/exfil',
        'repository_url' => 'https://github.com/acme/exfil.git',
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();

    expect(Package::where('name', 'acme/exfil')->exists())->toBeFalse();
});

it('accepts a git credential from an organization the caller administers', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create();

    $this->withToken(credWriteKey($org))->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/own',
        'repository_url' => 'https://github.com/acme/own.git',
        'git_credential_id' => $cred->id,
    ])->assertCreated();

    expect(Package::where('name', 'acme/own')->firstOrFail()->git_credential_id)->toBe($cred->id);
});

it('refuses to create a package on the admin surface with a foreign git credential', function () {
    Queue::fake();
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $foreign = GitCredential::factory()->for($theirs)->create();
    $admin = User::factory()->for($mine)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/exfil',
        'repository_url' => 'https://github.com/acme/exfil.git',
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();
});

it('refuses to retarget an existing package at a foreign git credential', function () {
    Queue::fake();
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $foreign = GitCredential::factory()->for($theirs)->create();
    $admin = User::factory()->for($mine)->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for($mine)->create();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/widget.git']);
    $package->groups()->sync([$group->id]);

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => $package->repository_url,
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();

    expect($package->fresh()->git_credential_id)->toBeNull();
});

it('never probes with a foreign git credential', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n"), '*' => Process::result('')]);
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $foreign = GitCredential::factory()->for($theirs)->create(['token' => 'ghp_victim']);
    $admin = User::factory()->for($mine)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();

    Process::assertNothingRan();
});
