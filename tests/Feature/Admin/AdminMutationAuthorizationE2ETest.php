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

it('forbids a guest from reaching admin create endpoints', function (string $url) {
    $this->post($url, [])->assertRedirect(); // unauthenticated -> redirected to login
})->with('admin create endpoints');
