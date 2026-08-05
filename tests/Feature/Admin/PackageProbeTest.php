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

it('previews a reachable repository with discovered name and versions', function () {
    Process::fake([
        '*ls-remote*' => Process::result(
            "ref: refs/heads/main\tHEAD\n"
            ."deadbeef\trefs/tags/v1.0.0\n"
            ."cafe1234\trefs/tags/v1.1.0\n",
        ),
        '*clone*' => Process::result(''),
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

it('reports an unreachable repository', function () {
    Process::fake([
        'git ls-remote*' => Process::result(output: '', errorOutput: 'fatal: Could not resolve host: nope.invalid', exitCode: 128),
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
