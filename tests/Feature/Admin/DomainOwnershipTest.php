<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A registry hostname is a globally unique, instance-wide resource: the row in `domains`
 * is the only thing that decides which tenant's packages are served at that host. Nothing
 * in the application can prove that the caller controls the DNS name, so attaching one is
 * reserved for the instance operator. These tests pin that boundary from both surfaces.
 */
beforeEach(function () {
    $this->customerOrg = Organization::factory()->create();
    $this->customerAdmin = User::factory()->for($this->customerOrg)->create(['role' => UserRole::Admin]);
    $this->customerGroup = Group::factory()->for($this->customerOrg)->create();

    // Admin of the operator organization — the instance operator (isSuperAdmin() is true).
    $this->operator = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('refuses a customer-org admin attaching a hostname to their own registry', function () {
    $this->actingAs($this->customerAdmin)
        ->post('/admin/domains', ['group_id' => $this->customerGroup->id, 'hostname' => 'packages.victim.test'])
        ->assertForbidden();

    expect(Domain::where('hostname', 'packages.victim.test')->exists())->toBeFalse();
});

it('refuses a customer-org admin attaching a hostname over the management API', function () {
    [, $plain] = ApiKey::issue($this->customerAdmin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)
        ->postJson("/api/v1/groups/{$this->customerGroup->id}/domains", [
            'group_id' => $this->customerGroup->id,
            'hostname' => 'packages.victim.test',
        ])
        ->assertForbidden();

    expect(Domain::where('hostname', 'packages.victim.test')->exists())->toBeFalse();
});

it('lets the operator attach a hostname to a customer registry', function () {
    $this->actingAs($this->operator)
        ->post('/admin/domains', ['group_id' => $this->customerGroup->id, 'hostname' => 'packages.customer.test'])
        ->assertRedirect();

    expect(Domain::where('hostname', 'packages.customer.test')->first()?->group_id)
        ->toBe($this->customerGroup->id);
});

it('lets the operator attach a hostname over the management API', function () {
    [, $plain] = ApiKey::issue($this->operator, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)
        ->postJson("/api/v1/groups/{$this->customerGroup->id}/domains", [
            'group_id' => $this->customerGroup->id,
            'hostname' => 'packages.customer.test',
        ])
        ->assertCreated();

    expect(Domain::where('hostname', 'packages.customer.test')->exists())->toBeTrue();
});

it('refuses the instance host itself, even for the operator', function () {
    config(['app.url' => 'https://registry.example.test']);

    $this->actingAs($this->operator)
        ->post('/admin/domains', ['group_id' => $this->customerGroup->id, 'hostname' => 'registry.example.test'])
        ->assertSessionHasErrors('hostname');

    expect(Domain::where('hostname', 'registry.example.test')->exists())->toBeFalse();
});

it('keeps the owning organization able to detach its own hostname', function () {
    $domain = Domain::factory()->for($this->customerGroup)->create();

    $this->actingAs($this->customerAdmin)->delete("/admin/domains/{$domain->id}")->assertRedirect();

    expect(Domain::find($domain->id))->toBeNull();
});

it('writes an audit record when a hostname is attached and detached', function () {
    $this->actingAs($this->operator)
        ->post('/admin/domains', ['group_id' => $this->customerGroup->id, 'hostname' => 'audited.customer.test'])
        ->assertRedirect();

    $domain = Domain::where('hostname', 'audited.customer.test')->firstOrFail();

    expect(Activity::where('subject_type', Domain::class)->where('subject_id', $domain->id)
        ->where('description', 'created')->exists())->toBeTrue();

    $this->actingAs($this->operator)->delete("/admin/domains/{$domain->id}")->assertRedirect();

    expect(Activity::where('subject_type', Domain::class)->where('subject_id', $domain->id)
        ->where('description', 'deleted')->exists())->toBeTrue();
});

it('still refuses the instance host when APP_URL was written without a scheme', function () {
    // The reserved-hostname rule reads the same APP_URL string: unparsed, it produced an
    // empty reserve list, so the console hostname could be handed to a registry.
    config(['app.url' => 'registry.example.test']);

    $this->actingAs($this->operator)
        ->post('/admin/domains', ['group_id' => $this->customerGroup->id, 'hostname' => 'registry.example.test'])
        ->assertSessionHasErrors('hostname');

    expect(Domain::where('hostname', 'registry.example.test')->exists())->toBeFalse();
});
