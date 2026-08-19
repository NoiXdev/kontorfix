<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

// `POST /admin/tokens` sits behind `password.confirm` (see routes/web.php): the same
// long-lived bearer credential as `settings/tokens`. This file exercises the CRUD and
// scoping behind that gate; the gate itself is covered by
// tests/Feature/Settings/CredentialPasswordConfirmationTest.php.
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

it('lists tokens for admins', function () {
    $org = Organization::factory()->create();
    RegistryToken::issue($org, 'ci', null);
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/tokens/Index')->has('tokens', 1));
});

it('creates a token and flashes its real plaintext to the tokens index it redirects to', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $response = $this->actingAs($admin)
        ->post('/admin/tokens', ['name' => 'kadenz-ci', 'organization_id' => $org->id, 'group_id' => $group->id]);

    // The redirect target is itself the thing under test: minting now happens from its own
    // `admin/tokens/create` page (TokenController::store() used to `back()`, which — once
    // the form left the index — would land there instead of the index, and that page
    // renders no flash at all). It must land specifically on the index, the only page that
    // shows the one-time reveal.
    $response->assertRedirect(route('admin.tokens.index'));

    $token = RegistryToken::where('name', 'kadenz-ci')->firstOrFail();
    $plain = session('plainTextToken');

    // Not merely "a string shaped like a token" — the actual credential that authenticates
    // as the row just created, checked against the real hash/lookup path
    // (RegistryToken::issue()'s stored token_hash and findByPlainText()), not a loose
    // prefix check.
    expect(hash('sha256', $plain))->toBe($token->token_hash)
        ->and(RegistryToken::findByPlainText($plain)?->is($token))->toBeTrue();

    // Follow the redirect and confirm the Inertia page actually carries it under the same
    // key `tokens/Index.vue` reads (`page.props.flash.plainTextToken`).
    $this->actingAs($admin)
        ->get(route('admin.tokens.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/tokens/Index')
            ->where('flash.plainTextToken', $plain));
});

it('creates an org-wide token when no group is given', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'org-wide', 'organization_id' => $org->id])
        ->assertRedirect();
    expect(RegistryToken::where('name', 'org-wide')->first()->group_id)->toBeNull();
});

it('validates that the group belongs to the chosen organization', function () {
    $org = Organization::factory()->create();
    $otherGroup = Group::factory()->for(Organization::factory())->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'x', 'organization_id' => $org->id, 'group_id' => $otherGroup->id])
        ->assertSessionHasErrors('group_id');
});

it('revokes tokens by deletion', function () {
    $org = Organization::factory()->create();
    [$token] = RegistryToken::issue($org, 'x', null);
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/tokens/{$token->id}")->assertRedirect();
    expect(RegistryToken::find($token->id))->toBeNull();
});

it('forbids members from managing tokens', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/tokens')->assertForbidden();
});

it('never exposes the token hash or plaintext in the index payload', function () {
    $org = Organization::factory()->create();
    RegistryToken::issue($org, 'ci', null);

    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/tokens')
        ->assertInertia(fn ($page) => $page
            ->has('tokens.0', fn ($token) => $token
                ->hasAll(['id', 'name', 'organization', 'group', 'ability', 'last_used_at', 'expires_at'])
                ->missing('token_hash')
                ->etc()
            )
        );
});

it('flashes the plaintext token only for a single request', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->post('/admin/tokens', ['name' => 'once', 'organization_id' => $org->id])
        ->assertSessionHas('plainTextToken');

    // First GET = redirect target: shows the plaintext and consumes the flash.
    $this->actingAs($admin)->get('/admin/tokens')
        ->assertInertia(fn ($page) => $page->whereNot('flash.plainTextToken', null)->etc());

    // Second GET: the plaintext must not appear again.
    $this->actingAs($admin)->get('/admin/tokens')
        ->assertInertia(fn ($page) => $page->where('flash.plainTextToken', null)->etc());
});
