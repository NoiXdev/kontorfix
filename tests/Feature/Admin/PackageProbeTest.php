<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Process;

function probeAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('validates the repository url scheme', function () {
    $this->actingAs(probeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'ftp://example.com/x',
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');
});

// The clone URL GitHub and GitLab show by default. It is the likeliest thing an operator
// pastes, and it fails the shape rules — so the message it produces is the one that decides
// whether the create mask is diagnosable. `Anlegen` stays disabled until the probe succeeds,
// so an English (or swallowed) message here is a dead end for a German-speaking operator.
it('rejects the default ssh clone url with the german message, not laravel\'s english default', function () {
    $response = $this->actingAs(probeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'git@github.com:acme/tools.git',
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');

    expect($response->json('errors.repository_url'))
        ->toContain('Die Repository-URL muss mit https:// oder ssh:// beginnen.')
        ->toContain('Bitte eine gültige https- oder ssh-Repository-URL angeben.')
        ->each->not->toContain('must start with');
});

// The bug this guards against is drift: the probe and the store endpoint validated the same
// field from two copies of the same rules, and only one of them translated the messages. The
// probe is a precondition for the store call, so any disagreement between them is a URL the
// operator is told two different things about — or, as happened, one thing in German and the
// same thing in English.
it('rejects a url with the same messages from the probe and the store endpoint', function () {
    $admin = probeAdmin();
    $url = 'git@github.com:acme/tools.git';

    $probe = $this->actingAs($admin)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => $url,
    ])->assertStatus(422);

    $store = $this->actingAs($admin)->postJson('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/tools',
        'repository_url' => $url,
        'group_ids' => [homeRegistryId($admin)],
    ])->assertStatus(422);

    expect($store->json('errors.repository_url'))->toEqual($probe->json('errors.repository_url'));
});

it('previews a reachable repository with discovered name and versions', function () {
    Process::fake([
        '*ls-remote*' => Process::result(
            "ref: refs/heads/main\tHEAD\n"
            ."deadbeef\trefs/tags/v1.0.0\n"
            ."cafe1234\trefs/tags/v1.1.0\n",
        ),
        '*clone*' => Process::result(''),
        // The clone is blobless, so the probe first asks the (already downloaded) tree
        // whether the manifest exists at all before fetching it.
        '*ls-tree*' => Process::result("composer.json\n"),
        '*show*' => Process::result('{"name":"acme/tools","description":"Handy tools"}'),
    ]);

    $this->actingAs(probeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('name', 'acme/tools')
        ->assertJsonPath('description', 'Handy tools')
        ->assertJsonPath('default_branch', 'main')
        ->assertJsonPath('versions', ['v1.1.0', 'v1.0.0']);
});

it('discovers the project name from a python pyproject.toml', function () {
    Process::fake([
        '*ls-remote*' => Process::result("ref: refs/heads/main\tHEAD\ndeadbeef\trefs/tags/v1.0.0\n"),
        '*clone*' => Process::result(''),
        '*ls-tree*' => Process::result("pyproject.toml\n"),
        '*show*' => Process::result("[project]\nname = \"acme-lib\"\ndescription = \"A handy lib\"\nversion = \"1.0.0\"\n"),
    ]);

    $this->actingAs(probeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'python',
        'repository_url' => 'https://github.com/acme/lib.git',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('name', 'acme-lib')
        ->assertJsonPath('description', 'A handy lib');
});

it('reports an unreachable repository', function () {
    Process::fake([
        '*ls-remote*' => Process::result(output: '', errorOutput: 'fatal: Could not resolve host: nope.invalid', exitCode: 128),
    ]);

    $this->actingAs(probeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://nope.invalid/x.git',
    ])->assertOk()->assertJsonPath('ok', false);
});

it('denies probing to non-operator members', function () {
    $member = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);

    $this->actingAs($member)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
    ])->assertForbidden();
});
