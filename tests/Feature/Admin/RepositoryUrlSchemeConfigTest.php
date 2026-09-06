<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Support\RepositoryUrlRules;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * RepositoryUrlRules::shape() used to hardcode `url:https,ssh` / `starts_with:https://,ssh://`
 * while the real sinks (RepositoryProbe::probe(), GitRepository::sync(), via GitUrlSafety)
 * honoured `kontorfix.vcs.allowed_schemes`. An operator who widened that config to reach an
 * internal git server over `http` got a create-form refusal for a URL the sync would happily
 * accept — one rule, two places, two answers.
 *
 * These tests pin the coupling in both directions, at every site that validates
 * `repository_url` through RepositoryUrlRules: the default config must stay exactly as
 * restrictive as before (no accidental widening), and a config that names an extra scheme
 * must be honoured by the form, not just by the sync sink.
 */
function schemeAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

function defaultSchemeConfig(): void
{
    config(['kontorfix.vcs.allowed_schemes' => ['https', 'ssh']]);
}

function widenedSchemeConfig(): void
{
    config(['kontorfix.vcs.allowed_schemes' => ['https', 'ssh', 'http']]);
}

// --- StorePackageRequest (admin create, and its API equivalent) -----------------------

it('still refuses an http repository url on package creation under the default scheme config', function () {
    defaultSchemeConfig();
    $admin = schemeAdmin();

    $this->actingAs($admin)->postJson('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/widget',
        'repository_url' => 'http://git.example.com/acme/widget.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');
});

it('accepts an http repository url on package creation once the scheme allowlist is widened', function () {
    widenedSchemeConfig();
    Queue::fake();
    $admin = schemeAdmin();

    $this->actingAs($admin)->postJson('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/widget',
        'repository_url' => 'http://git.example.com/acme/widget.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertCreated();

    expect(Package::where('name', 'acme/widget')->first()?->repository_url)
        ->toBe('http://git.example.com/acme/widget.git');
});

it('accepts an http repository url on the api create path once the scheme allowlist is widened', function () {
    widenedSchemeConfig();
    Queue::fake();
    $admin = schemeAdmin();
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/api-widget',
        'repository_url' => 'http://git.example.com/acme/api-widget.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertCreated();
});

// --- PackageController::probe() --------------------------------------------------------

it('still refuses an http repository url from the probe endpoint under the default scheme config', function () {
    defaultSchemeConfig();
    Process::fake();

    $this->actingAs(schemeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'http://git.example.com/acme/widget.git',
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');

    Process::assertNothingRan();
});

it('accepts an http repository url from the probe endpoint once the scheme allowlist is widened', function () {
    widenedSchemeConfig();
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);

    // The probe may still legitimately fail for other reasons (host reachability, etc.) —
    // what this proves is that the *form* no longer rejects the URL before it ever reaches
    // the probe, which is the bug: a 422 with a repository_url error means shape() disagreed
    // with GitUrlSafety about which schemes are allowed.
    $this->actingAs(schemeAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'http://git.example.com/acme/widget.git',
    ])->assertOk()->assertJsonMissingValidationErrors('repository_url');
});

// --- PackageController::update() --------------------------------------------------------

it('still refuses an http repository url on package update under the default scheme config', function () {
    defaultSchemeConfig();
    Queue::fake();
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for($org)->create();
    $this->actingAs($admin)->postJson('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/widget',
        'repository_url' => 'https://github.com/acme/widget.git',
        'group_ids' => [$group->id],
    ])->assertCreated();
    $package = Package::where('name', 'acme/widget')->firstOrFail();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'http://git.example.com/acme/widget.git',
    ])->assertSessionHasErrors('repository_url');
});

it('accepts an http repository url on package update once the scheme allowlist is widened', function () {
    defaultSchemeConfig();
    Queue::fake();
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for($org)->create();
    $this->actingAs($admin)->postJson('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/widget',
        'repository_url' => 'https://github.com/acme/widget.git',
        'group_ids' => [$group->id],
    ])->assertCreated();
    $package = Package::where('name', 'acme/widget')->firstOrFail();

    widenedSchemeConfig();

    $this->actingAs($admin)->put("/admin/packages/{$package->id}", [
        'repository_url' => 'http://git.example.com/acme/widget.git',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($package->fresh()->repository_url)->toBe('http://git.example.com/acme/widget.git');
});

// --- Messages stay truthful under the default (unwidened) config ----------------------

it('keeps the exact german shape messages under the default scheme config', function () {
    defaultSchemeConfig();

    expect(RepositoryUrlRules::messages())->toBe([
        'repository_url.starts_with' => 'Die Repository-URL muss mit https:// oder ssh:// beginnen.',
        'repository_url.url' => 'Bitte eine gültige https- oder ssh-Repository-URL angeben.',
    ]);
});

it('names the widened scheme in the shape messages once the allowlist grows', function () {
    widenedSchemeConfig();

    expect(RepositoryUrlRules::messages())->toBe([
        'repository_url.starts_with' => 'Die Repository-URL muss mit https://, ssh:// oder http:// beginnen.',
        'repository_url.url' => 'Bitte eine gültige https-, ssh- oder http-Repository-URL angeben.',
    ]);
});
