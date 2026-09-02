<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Models\User;

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['name' => 'Org A']);
    $this->orgB = Organization::factory()->create(['name' => 'Org B']);
    // A super-admin so the scope switch can span all orgs or narrow to one.
    $this->super = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->groupA = Group::factory()->for($this->orgA)->create(['name' => 'Reg A']);
    $this->groupB = Group::factory()->for($this->orgB)->create(['name' => 'Reg B']);

    $this->pkgA = Package::factory()->inOrgOf($this->groupA)->create(['name' => 'a/one']);
    $this->groupA->packages()->attach($this->pkgA->id);
    $this->pkgB = Package::factory()->inOrgOf($this->groupB)->create(['name' => 'b/one']);
    $this->groupB->packages()->attach($this->pkgB->id);

    Domain::factory()->for($this->groupA)->create(['hostname' => 'a.example.test']);
    Domain::factory()->for($this->groupB)->create(['hostname' => 'b.example.test']);
    Upstream::factory()->for($this->groupA)->create();
    Upstream::factory()->for($this->groupB)->create();
    RegistryToken::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'tok-a']);
    RegistryToken::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'tok-b']);
});

function selectScope(User $user, ?string $orgId): void
{
    test()->actingAs($user)->post('/admin/scope', ['organization_id' => $orgId])->assertRedirect();
}

it('filters packages by the active scope', function () {
    selectScope($this->super, $this->orgA->id);
    $this->actingAs($this->super)->get('/admin/packages')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'a/one'));

    selectScope($this->super, null);
    $this->actingAs($this->super)->get('/admin/packages')
        ->assertInertia(fn ($p) => $p->has('packages.data', 2));
});

it('filters domains by the active scope', function () {
    selectScope($this->super, $this->orgB->id);
    $this->actingAs($this->super)->get('/admin/domains')
        ->assertInertia(fn ($p) => $p->has('domains', 1)->where('domains.0.hostname', 'b.example.test'));
});

it('filters upstreams by the active scope', function () {
    selectScope($this->super, $this->orgA->id);
    $this->actingAs($this->super)->get('/admin/upstreams')
        ->assertInertia(fn ($p) => $p->has('upstreams', 1)->where('upstreams.0.group_id', $this->groupA->id));
});

it('filters tokens by the active scope', function () {
    selectScope($this->super, $this->orgA->id);
    $this->actingAs($this->super)->get('/admin/tokens')
        ->assertInertia(fn ($p) => $p->has('tokens', 1)->where('tokens.0.name', 'tok-a'));

    selectScope($this->super, null);
    $this->actingAs($this->super)->get('/admin/tokens')
        ->assertInertia(fn ($p) => $p->has('tokens', 2));
});

it('scopes the dashboard stats to the active scope', function () {
    selectScope($this->super, $this->orgA->id);
    $this->actingAs($this->super)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('stats.packages', 1)->where('stats.groups', 1)->where('stats.domains', 1));

    selectScope($this->super, null);
    $this->actingAs($this->super)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('stats.packages', 2)->where('stats.groups', 2)->where('stats.domains', 2));
});
