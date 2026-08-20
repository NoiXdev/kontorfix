<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Every admin write endpoint sits behind the `operator` gate. This matrix proves a plain
 * member (no console rights) is refused at each create endpoint before any body is even
 * validated — so a new admin form can never be shipped without its access gate.
 */
beforeEach(function () {
    $this->member = User::factory()->for(Organization::factory()->create(['is_operator' => false]))
        ->create(['role' => UserRole::Member]);
});

dataset('admin create endpoints', [
    'groups' => ['/admin/groups'],
    'packages' => ['/admin/packages'],
    'domains' => ['/admin/domains'],
    'upstreams' => ['/admin/upstreams'],
    'users' => ['/admin/users'],
    'robots' => ['/admin/robots'],
    'tokens' => ['/admin/tokens'],
    'webhooks' => ['/admin/webhooks'],
    'incoming-webhooks' => ['/admin/incoming-webhooks'],
    'oidc' => ['/admin/oidc'],
    'organizations' => ['/admin/organizations'],
    'git-credentials' => ['/admin/git-credentials'],
]);

it('forbids a member from posting to admin create endpoints', function (string $url) {
    $this->actingAs($this->member)->post($url, [])->assertForbidden();
})->with('admin create endpoints');

it('forbids a member from updating instance settings', function () {
    $this->actingAs($this->member)->put('/admin/system', ['registration_enabled' => true])->assertForbidden();
    $this->actingAs($this->member)->put('/admin/storage', [])->assertForbidden();
    $this->actingAs($this->member)->put('/admin/mail', [])->assertForbidden();
});

it('forbids a member from updating an organization', function () {
    $org = Organization::factory()->create();

    $this->actingAs($this->member)
        ->put("/admin/organizations/{$org->id}", ['name' => $org->name, 'notification_cadence' => 'daily'])
        ->assertForbidden();
});

// `organizations` sits in the `super`-only group (routes/web.php), not the `operator`
// group most of `/admin` lives behind — an org admin/maintainer has console access but
// no business touching another organization's row. The plain member above cannot tell
// these two gates apart: EnsureOperator and EnsureSuperAdmin both refuse a member
// identically, so a member-only test would stay green even if `organizations.update`
// were mistakenly moved into the `operator` group. This uses an admin of a *different*,
// non-operator organization instead — someone who passes `operator` (they administer
// their own org) but must still fail `super` — so a misrouted gate is actually caught.
it('forbids an organization admin who is not a super-admin from updating another organization', function () {
    $orgAdmin = User::factory()->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create();

    $this->actingAs($orgAdmin)
        ->put("/admin/organizations/{$org->id}", ['name' => $org->name, 'notification_cadence' => 'daily'])
        ->assertForbidden();
});

it('forbids a guest from reaching admin create endpoints', function (string $url) {
    $this->post($url, [])->assertRedirect(); // unauthenticated -> redirected to login
})->with('admin create endpoints');
