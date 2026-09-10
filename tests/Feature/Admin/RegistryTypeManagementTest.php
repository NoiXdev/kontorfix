<?php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Registry\RegistryTypeService;

function svc(): RegistryTypeService
{
    return app(RegistryTypeService::class);
}

it('enables every type by default and lets an org inherit', function () {
    $org = Organization::factory()->create();

    expect(svc()->globalTypes())->toContain('composer', 'npm', 'python')
        ->and(svc()->effectiveFor($org))->toContain('composer', 'npm', 'python')
        ->and(svc()->isEnabledFor($org, PackageType::Python))->toBeTrue();
});

it('treats the global setting as a ceiling the org cannot exceed', function () {
    SystemSetting::current()->update(['enabled_registry_types' => ['composer']]);
    $org = Organization::factory()->create(['enabled_registry_types' => ['composer', 'npm']]);

    // npm is globally off, so the org's attempt to allow it has no effect.
    expect(svc()->effectiveFor($org))->toBe(['composer'])
        ->and(svc()->isEnabledFor($org, PackageType::Npm))->toBeFalse()
        ->and(svc()->isGloballyEnabled(PackageType::Npm))->toBeFalse();
});

it('lets an org restrict within the global set', function () {
    $org = Organization::factory()->create(['enabled_registry_types' => ['composer']]);

    expect(svc()->effectiveFor($org))->toBe(['composer'])
        ->and(svc()->isEnabledFor($org, PackageType::Composer))->toBeTrue()
        ->and(svc()->isEnabledFor($org, PackageType::Npm))->toBeFalse();
});

it('404s the registry protocol for a globally disabled type', function () {
    SystemSetting::current()->update(['enabled_registry_types' => ['composer']]); // npm off
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz', 'public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/leftpad')->assertNotFound();
    // Composer stays available.
    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/packages.json')->assertOk();
});

it('404s the registry protocol for a type disabled only for that org', function () {
    // python globally on, but off for this org.
    $org = Organization::factory()->create(['enabled_registry_types' => ['composer', 'npm']]);
    $group = Group::factory()->for($org)->create(['slug' => 'kadenz', 'public' => true]);
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/simple/demo/')->assertNotFound();
});

it('blocks creating a package of a globally disabled type', function () {
    SystemSetting::current()->update(['enabled_registry_types' => ['composer', 'npm']]); // python off
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/packages', ['type' => 'python', 'name' => 'demo'])
        ->assertSessionHasErrors('type');
});

it('persists the global registry-type setting from the system page', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put('/admin/system', [
        'registration_enabled' => false,
        'enabled_registry_types' => ['composer', 'python'],
    ])->assertRedirect();

    expect(svc()->globalTypes())->toBe(['composer', 'python']);
});

it('404s the org-level composer endpoint for a globally disabled type', function () {
    SystemSetting::current()->update(['enabled_registry_types' => ['npm']]); // composer off
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/packages.json')->assertNotFound();
});

it('404s the org-level composer endpoint for a type disabled only for that org', function () {
    // composer globally on, but this org restricts itself to npm only.
    $org = Organization::factory()->create(['enabled_registry_types' => ['npm']]);
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/packages.json')->assertNotFound();
});

it('still serves the org-level composer endpoint when the type is enabled', function () {
    $org = Organization::factory()->create(['enabled_registry_types' => ['composer', 'npm']]);
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/packages.json')->assertOk();
});

it('leaves group-path type gating unaffected by the org branch', function () {
    // The group-path assertions above (line 43+) already pin this, but re-asserted here,
    // right next to the org-branch cases, so a future edit to EnsureRegistryTypeEnabled that
    // breaks the group `if` while "fixing" the new `elseif` shows up in the same file.
    SystemSetting::current()->update(['enabled_registry_types' => ['composer']]); // npm off
    $group = Group::factory()->for(Organization::factory())->create(['public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/leftpad')->assertNotFound();
    $this->withHeaders(tokenHeaderFor($group))->get(registryPath($group).'/packages.json')->assertOk();
});

it('clamps a per-org override to the global ceiling', function () {
    SystemSetting::current()->update(['enabled_registry_types' => ['composer', 'npm']]); // python off
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create();

    // Trying to allow python (off globally) + composer → only composer survives the clamp.
    $this->actingAs($admin)->put("/admin/organizations/{$org->id}/registry-types", [
        'enabled_registry_types' => ['python', 'composer'],
    ])->assertRedirect();

    expect(svc()->effectiveFor($org->fresh()))->toBe(['composer']);
});
