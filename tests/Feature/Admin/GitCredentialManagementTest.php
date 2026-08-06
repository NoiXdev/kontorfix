<?php

use App\Enums\GitProvider;
use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function credAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('creates an org-scoped credential with an encrypted, hidden token', function () {
    $org = Organization::factory()->create();

    $this->actingAs(credAdmin($org))->post('/admin/git-credentials', [
        'name' => 'GH Deploy', 'organization_id' => $org->id, 'provider' => 'github', 'token' => 'ghp_secret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cred = GitCredential::firstOrFail();
    expect($cred->token)->toBe('ghp_secret')
        ->and($cred->provider)->toBe(GitProvider::GitHub)
        ->and($cred->organization_id)->toBe($org->id)
        ->and($cred->toArray())->not->toHaveKey('token')
        ->and(DB::table('git_credentials')->where('id', $cred->id)->value('token'))->not->toBe('ghp_secret');
});

it('scopes the listing and blocks cross-org management', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = credAdmin($orgA);
    $own = GitCredential::factory()->for($orgA)->create(['name' => 'mine']);
    $foreign = GitCredential::factory()->for($orgB)->create(['name' => 'theirs']);

    $this->actingAs($adminA)->get('/admin/git-credentials')
        ->assertInertia(fn ($p) => $p->has('credentials', 1)->where('credentials.0.name', 'mine'));

    $this->actingAs($adminA)->put("/admin/git-credentials/{$foreign->id}", [
        'name' => 'x', 'provider' => 'github',
    ])->assertForbidden();
    $this->actingAs($adminA)->delete("/admin/git-credentials/{$foreign->id}")->assertForbidden();
});

it('keeps the token on update when left blank and replaces it when provided', function () {
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['token' => 'old-token']);

    $this->actingAs(credAdmin($org))->put("/admin/git-credentials/{$cred->id}", [
        'name' => 'renamed', 'provider' => 'gitlab', 'token' => '',
    ])->assertRedirect();
    expect($cred->fresh()->token)->toBe('old-token')
        ->and($cred->fresh()->provider)->toBe(GitProvider::GitLab);

    $this->actingAs(credAdmin($org))->put("/admin/git-credentials/{$cred->id}", [
        'name' => 'renamed', 'provider' => 'gitlab', 'token' => 'new-token',
    ])->assertRedirect();
    expect($cred->fresh()->token)->toBe('new-token');
});

it('tests a credential against a repository via ls-remote', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create();

    $this->actingAs(credAdmin($org))->postJson("/admin/git-credentials/{$cred->id}/test", [
        'repository_url' => 'https://github.com/acme/private.git',
    ])->assertOk()->assertJsonPath('ok', true);
});

it('assigns a credential to a package and resolves its git auth', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitLab, 'token' => 'glpat-x']);

    $this->actingAs(credAdmin($org))->post('/admin/packages', [
        'type' => 'composer', 'name' => 'acme/lib',
        'repository_url' => 'https://gitlab.com/acme/lib.git',
        'git_credential_id' => $cred->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $auth = Package::where('name', 'acme/lib')->firstOrFail()->gitAuth();
    expect($auth['token'])->toBe('glpat-x')
        ->and($auth['provider'])->toBe(GitProvider::GitLab);
});

it('forbids assigning a foreign-org credential to a package', function () {
    Queue::fake();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = GitCredential::factory()->for($orgB)->create();

    $this->actingAs(credAdmin($orgA))->post('/admin/packages', [
        'type' => 'composer', 'name' => 'acme/lib',
        'repository_url' => 'https://gitlab.com/acme/lib.git',
        'git_credential_id' => $foreign->id,
    ])->assertForbidden();
});
