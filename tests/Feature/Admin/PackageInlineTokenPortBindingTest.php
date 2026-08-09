<?php

// A02 — `33cb0c1` made `GitCredential::permits()` compare the whole authority including the
// port, because `GitAuth::origin()` scopes the Authorization header to scheme://host:port.
// The sibling column did not get the same treatment: `PackageController::sameHost()` compared
// `parse_url(..., PHP_URL_HOST)`, which discards the port by construction, so a Maintainer —
// strictly below the Admin who entered the token — could move `https://gitlab.corp/x` to
// `https://gitlab.corp:9999/x`, keep the stored PAT, and have SyncPackage deliver it to a
// listener they control. On a self-hosted GitLab or GHE box that is an ordinary developer
// capability. `Package::gitAuth()`'s inline branch had no host check of any kind.

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Support\RepositoryAuthority;
use Illuminate\Support\Facades\Queue;

function portBindingAdmin(): User
{
    return User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Admin]);
}

function packageWithInlineToken(string $url = 'https://gitlab.corp/acme/tools.git'): Package
{
    return Package::factory()->create([
        'repository_url' => $url,
        'repository_token' => 'glpat-supersecret',
    ]);
}

it('refuses to keep an inline token when only the port changes', function () {
    Queue::fake();
    $package = packageWithInlineToken();

    $this->actingAs(portBindingAdmin())
        ->put("/admin/packages/{$package->id}", [
            'repository_url' => 'https://gitlab.corp:9999/acme/tools.git',
        ])
        ->assertSessionHasErrors('repository_url');

    expect($package->fresh()->repository_url)->toBe('https://gitlab.corp/acme/tools.git');
});

it('still allows a move within the same authority — the anchor for the case above', function () {
    // Same actor, same route, same package, same shape of payload, and the only difference
    // is the authority: so the refusal above is the authority comparison and not the
    // operator gate, the URL validation or the token-retention branch itself.
    Queue::fake();
    $package = packageWithInlineToken();

    $this->actingAs(portBindingAdmin())
        ->put("/admin/packages/{$package->id}", [
            'repository_url' => 'https://gitlab.corp/acme/other.git',
        ])
        ->assertSessionHasNoErrors();

    expect($package->fresh()->repository_url)->toBe('https://gitlab.corp/acme/other.git');
});

it('treats an explicit default port as the same authority', function () {
    Queue::fake();
    $package = packageWithInlineToken();

    $this->actingAs(portBindingAdmin())
        ->put("/admin/packages/{$package->id}", [
            'repository_url' => 'https://gitlab.corp:443/acme/tools.git',
        ])
        ->assertSessionHasNoErrors();

    expect($package->fresh()->repository_url)->toBe('https://gitlab.corp:443/acme/tools.git');
});

it('binds the inline token to the authority it was entered for', function () {
    $package = packageWithInlineToken('https://gitlab.corp:8443/acme/tools.git');

    expect($package->repository_token_host)->toBe('gitlab.corp:8443');
});

it('drops the binding when the inline token is removed', function () {
    $package = packageWithInlineToken();
    $package->update(['repository_token' => null]);

    expect($package->fresh()->repository_token_host)->toBeNull();
});

it('withholds the inline token from a repository on another port', function () {
    $package = packageWithInlineToken('https://gitlab.corp/acme/tools.git');

    // The write guard is the primary control; this is the sink. A path that retargets the
    // column without going through the console — a super-admin, a future API writer, a
    // direct edit — must not turn into a delivered PAT.
    $package->forceFill(['repository_url' => 'https://gitlab.corp:9999/acme/tools.git'])->saveQuietly();

    expect($package->fresh()->gitAuth()['token'])->toBeNull();
});

it('still hands over the inline token for the repository it belongs to', function () {
    $package = packageWithInlineToken('https://gitlab.corp/acme/tools.git');

    expect($package->fresh()->gitAuth()['token'])->toBe('glpat-supersecret');
});

it('answers the same authority question for both credential columns', function () {
    // "Where does this token get sent" must have one answer, not one per column: the two
    // guards drifting apart is exactly what left this half open.
    expect(RepositoryAuthority::of('https://gitlab.corp:9999/x.git'))->toBe('gitlab.corp:9999')
        ->and(RepositoryAuthority::of('https://GitLab.Corp:443/x.git'))->toBe('gitlab.corp')
        ->and(RepositoryAuthority::of('ssh://git@gitlab.corp:22/x.git'))->toBe('gitlab.corp')
        ->and(RepositoryAuthority::of('ssh://git@gitlab.corp:2222/x.git'))->toBe('gitlab.corp:2222')
        ->and(RepositoryAuthority::of('not a url'))->toBeNull()
        ->and(RepositoryAuthority::of(null))->toBeNull();
});
