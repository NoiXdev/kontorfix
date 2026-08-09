<?php

// A02 (carried partial B5) — the `host` backfill deliberately declines to bind a credential
// whose assigned packages point somewhere other than the provider's canonical host: that is
// a self-hosted GHE or GitLab PAT and the migration has no safe way to learn its address, so
// it leaves the column null and warns. `allowedHost()` then fell back to
// `$this->provider->defaultHost()`, which turned "we refused to bind this" into "bound to
// github.com" — and the self-hosted PAT was transmitted to the public provider, where it is
// useless but disclosed. The veto has to mean refusal.

use App\Enums\GitProvider;
use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Process;

function unboundHostAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('refuses a credential the backfill left unbound rather than aiming it at the provider', function () {
    $credential = GitCredential::factory()->create(['provider' => GitProvider::GitHub, 'host' => null]);

    expect($credential->allowedHost())->toBeNull()
        ->and($credential->permits('https://github.com/anyone/anything.git'))->toBeFalse()
        ->and($credential->permits('https://ghe.corp/acme/private.git'))->toBeFalse();
});

it('still permits a credential that names its host — the anchor for the case above', function () {
    // Identical row apart from the column under test, so the refusal above is the empty
    // binding and not the URL, the provider or permits() failing closed on everything.
    $credential = GitCredential::factory()->create(['provider' => GitProvider::GitHub, 'host' => 'github.com']);

    expect($credential->permits('https://github.com/anyone/anything.git'))->toBeTrue();
});

it('will not probe with an unbound credential', function () {
    Process::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $credential = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'host' => null]);

    $this->actingAs(unboundHostAdmin($org))->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
        'git_credential_id' => $credential->id,
    ])->assertStatus(422)->assertJsonValidationErrors('repository_url');

    // Refused before anything was dialled, so the token was never transmitted.
    Process::assertNothingRan();
});

it('probes fine with the same credential once its host is named', function () {
    Process::fake([
        '*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n"),
        '*clone*' => Process::result(''),
        '*show*' => Process::result('{"name":"acme/tools"}'),
    ]);
    $org = Organization::factory()->create(['is_operator' => true]);
    $credential = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'host' => 'github.com']);

    $this->actingAs(unboundHostAdmin($org))->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
        'git_credential_id' => $credential->id,
    ])->assertOk()->assertJsonPath('ok', true);
});

it('keeps writing the provider default into the column, so no console credential is unbound', function () {
    // Removing the fallback is only safe because the console materialises the default into
    // the row. Nobody who created a credential through the UI loses it; the null column is
    // exactly the set of rows the migration refused to decide for.
    $org = Organization::factory()->create(['is_operator' => true]);

    $this->actingAs(unboundHostAdmin($org))->post('/admin/git-credentials', [
        'name' => 'GitHub PAT',
        'provider' => 'github',
        'token' => 'ghp_supersecret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(GitCredential::where('name', 'GitHub PAT')->firstOrFail())
        ->host->toBe('github.com');
});
