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
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    // Admin of Org A only — a per-org role, not a super-admin.
    $this->adminA = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin]);

    $this->groupA = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();
});

it('forbids deleting a foreign orgs domain but allows the own', function () {
    $foreign = Domain::factory()->for($this->groupB)->create();
    $own = Domain::factory()->for($this->groupA)->create();

    $this->actingAs($this->adminA)->delete("/admin/domains/{$foreign->id}")->assertForbidden();
    expect(Domain::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->adminA)->delete("/admin/domains/{$own->id}")->assertRedirect();
    expect(Domain::find($own->id))->toBeNull();
});

it('forbids creating a domain on a foreign orgs registry', function () {
    $this->actingAs($this->adminA)->post('/admin/domains', [
        'group_id' => $this->groupB->id, 'hostname' => 'evil.example.test',
    ])->assertForbidden();
    expect(Domain::where('hostname', 'evil.example.test')->exists())->toBeFalse();
});

it('forbids deleting a foreign orgs upstream but allows the own', function () {
    $foreign = Upstream::factory()->for($this->groupB)->create();
    $own = Upstream::factory()->for($this->groupA)->create();

    $this->actingAs($this->adminA)->delete("/admin/upstreams/{$foreign->id}")->assertForbidden();
    expect(Upstream::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->adminA)->delete("/admin/upstreams/{$own->id}")->assertRedirect();
    expect(Upstream::find($own->id))->toBeNull();
});

it('forbids creating an upstream on a foreign orgs registry', function () {
    $this->actingAs($this->adminA)->post('/admin/upstreams', [
        'group_id' => $this->groupB->id, 'type' => 'composer', 'url' => 'https://repo.evil.test', 'policy' => 'proxy',
    ])->assertForbidden();
});

it('forbids revoking a foreign orgs token but allows the own', function () {
    $foreign = RegistryToken::factory()->create(['organization_id' => $this->orgB->id]);
    $own = RegistryToken::factory()->create(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->adminA)->delete("/admin/tokens/{$foreign->id}")->assertForbidden();
    expect(RegistryToken::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->adminA)->delete("/admin/tokens/{$own->id}")->assertRedirect();
    expect(RegistryToken::find($own->id))->toBeNull();
});

it('forbids issuing a token for a foreign organization', function () {
    // Minting is behind `password.confirm`; the cross-org refusal is what is under test.
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->actingAs($this->adminA)->post('/admin/tokens', [
        'name' => 'sneaky', 'organization_id' => $this->orgB->id,
    ])->assertForbidden();
    expect(RegistryToken::where('name', 'sneaky')->exists())->toBeFalse();
});

it('forbids viewing or deleting a package that lives only in a foreign registry', function () {
    $foreignPkg = Package::factory()->inOrgOf($this->groupB)->create();
    $this->groupB->packages()->attach($foreignPkg->id);

    $this->actingAs($this->adminA)->get("/admin/packages/{$foreignPkg->id}")->assertForbidden();
    $this->actingAs($this->adminA)->delete("/admin/packages/{$foreignPkg->id}")->assertForbidden();
    expect(Package::find($foreignPkg->id))->not->toBeNull();
});

it('allows viewing a package shared into the own registry', function () {
    // Owned by Org B; deliberately also attached to Org A's registry below to prove
    // that a package shared in remains visible — the org-pairing sweep guard in
    // tests/Pest.php is expected to trip on the groupA attach line, and stays doing so.
    $shared = Package::factory()->inOrgOf($this->groupB)->create();
    // The same package is attached to both a foreign and the own registry.
    $this->groupB->packages()->attach($shared->id);
    $this->groupA->packages()->attach($shared->id);

    $this->actingAs($this->adminA)->get("/admin/packages/{$shared->id}")->assertOk();
});
