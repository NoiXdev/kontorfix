<?php

use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** A Docker repository in a registry, visible to its own organization's admin. */
function retentionCardPackage(): Package
{
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->docker()->create();
    $group->packages()->attach($package);

    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'latest',
        'manifest_id' => OciManifest::factory()->for($package)->create()->id,
        'pushed_at' => '2026-09-07 00:00:00',
    ]);

    return $package;
}

it('shows the resolved policy, its tier, and the dry run on the package page', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Vorgabe', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);
    $package = retentionCardPackage();

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $package))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.policy.name', 'Vorgabe')
            // 'instance', not 'package': the package names no policy of its own, so the
            // card has to say which tier the resolution came from.
            ->where('retention.tier', 'instance')
            ->where('retention.rules.0', 'Letzte 1 behalten')
            ->where('retention.dry_run.removed_count', 0)
            ->where('retention.can_assign', true)
            ->has('retention.policies', 1));
});

it('reports the package tier when the package names its own policy', function () {
    $own = RetentionPolicy::factory()->create(['name' => 'Eigene']);
    $package = retentionCardPackage();
    $package->update(['retention_policy_id' => $own->id]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $package))
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.policy.name', 'Eigene')
            ->where('retention.tier', 'package'));
});

it('says explicitly that nothing is removed when no policy resolves', function () {
    $package = retentionCardPackage();

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $package))
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.policy', null)
            ->where('retention.tier', null)
            ->where('retention.dry_run', null));
});

it('tells an organization admin which policy applies, read-only', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);
    $package = retentionCardPackage();

    $this->actingAs(adminOf($package->organization))
        ->get(route('admin.packages.show', $package))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.policy.name', 'Vorgabe')
            ->where('retention.can_assign', false)
            // No selector for an org admin — and no catalogue of the instance's policies
            // either: which rule sets exist is operator config.
            ->has('retention.policies', 0));
});

it('lets a super-admin re-point a package at another policy', function () {
    $policy = RetentionPolicy::factory()->create();
    $package = retentionCardPackage();

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $policy->id])
        ->assertRedirect();

    expect($package->fresh()->retention_policy_id)->toBe($policy->id);

    // And back to inherit.
    $this->actingAs(superAdmin())
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => null])
        ->assertRedirect();

    expect($package->fresh()->retention_policy_id)->toBeNull();
});

it('refuses an organization admin, and changes nothing', function () {
    $policy = RetentionPolicy::factory()->create();
    $package = retentionCardPackage();

    $this->actingAs(adminOf($package->organization))
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $policy->id])
        ->assertForbidden();

    // Both halves: the §3 decision is that the instance default is not opt-out-able by
    // anyone below super — "refused" and "refused but applied" must not pass the same test.
    expect($package->fresh()->retention_policy_id)->toBeNull();
});

it('refuses retention assignment on a non-Docker package', function () {
    $composer = Package::factory()->create();
    $policy = RetentionPolicy::factory()->create();

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.retention.update', $composer), ['retention_policy_id' => $policy->id])
        ->assertStatus(409);

    expect($composer->fresh()->retention_policy_id)->toBeNull();
});

it('applies retention for one repository from its card, and logs the causer', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Knapp', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionCardPackage();
    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'alt',
        'manifest_id' => OciManifest::factory()->for($package)->create()->id,
        'pushed_at' => '2020-01-01 00:00:00',
    ]);
    $package->update(['retention_policy_id' => $policy->id]);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('admin.packages.retention.apply', $package))
        ->assertRedirect();

    expect($package->ociTags()->pluck('name')->all())->toBe(['latest'])
        ->and(Activity::where('log_name', 'retention')->sole()->causer_id)->toBe($admin->id);
});

it('refuses the per-package apply for an organization admin, and removes nothing', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionCardPackage();
    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'alt',
        'manifest_id' => OciManifest::factory()->for($package)->create()->id,
        'pushed_at' => '2020-01-01 00:00:00',
    ]);
    $package->update(['retention_policy_id' => $policy->id]);

    $this->actingAs(adminOf($package->organization))
        ->post(route('admin.packages.retention.apply', $package))
        ->assertForbidden();

    expect($package->ociTags()->count())->toBe(2);
});
