<?php

// The provider resource exposes index/create/store/destroy and nothing else, so the only
// way to change a leaked client_secret was to delete the provider and create it again —
// which cascades away every OidcIdentity linked to it. Rotating a secret therefore meant
// unlinking every SSO user, and the audit recorded the obvious consequence: the friction
// discourages rotating exactly the secret most in need of it.
//
// This is the single-purpose route that closes that, mirroring the `trust` toggle beside
// it rather than dragging in a full provider-edit form.

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->superAdmin = User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Admin]);
});

it('rotates the client secret', function () {
    $provider = OidcProvider::factory()->create(['client_secret' => 'old-secret']);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.secret', $provider), ['client_secret' => 'new-secret'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($provider->fresh()->client_secret)->toBe('new-secret');
});

it('keeps every linked identity, which is the whole reason this route exists', function () {
    $provider = OidcProvider::factory()->create(['client_secret' => 'old-secret']);
    $user = User::factory()->create();
    $provider->identities()->create(['user_id' => $user->id, 'subject' => 'sub-1']);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.secret', $provider), ['client_secret' => 'new-secret']);

    expect($provider->fresh()->identities()->count())->toBe(1);
});

it('stores the rotated secret encrypted, not in the clear', function () {
    $provider = OidcProvider::factory()->create(['client_secret' => 'old-secret']);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.secret', $provider), ['client_secret' => 'new-secret']);

    $raw = DB::table('oidc_providers')->where('id', $provider->id)->value('client_secret');

    expect($raw)->not->toContain('new-secret');
});

it('refuses an empty secret rather than silently clearing authentication', function () {
    $provider = OidcProvider::factory()->create(['client_secret' => 'old-secret']);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.secret', $provider), ['client_secret' => ''])
        ->assertSessionHasErrors('client_secret');

    expect($provider->fresh()->client_secret)->toBe('old-secret');
});

it('refuses a caller who is not a super-admin', function () {
    $provider = OidcProvider::factory()->create(['client_secret' => 'old-secret']);
    $outsider = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider)
        ->patch(route('admin.oidc.secret', $provider), ['client_secret' => 'new-secret'])
        ->assertForbidden();

    expect($provider->fresh()->client_secret)->toBe('old-secret');
});
