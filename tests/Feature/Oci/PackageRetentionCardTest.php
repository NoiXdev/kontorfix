<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Models\User;
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

it('tells an organization admin which policy applies, and offers only published policies', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    RetentionPolicy::factory()->create(['name' => 'Intern']);
    $published = RetentionPolicy::factory()->create(['name' => 'Veröffentlicht', 'is_global' => true]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);
    $package = retentionCardPackage();

    $this->actingAs(adminOf($package->organization))
        ->get(route('admin.packages.show', $package))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.policy.name', 'Vorgabe')
            // The owning organization's admin may set the package tier now — the operator
            // decision that made the instance default a default rather than a mandate.
            ->where('retention.can_assign', true)
            // ...but the selector carries only PUBLISHED policies: which other rule sets
            // exist on the instance is operator configuration.
            ->has('retention.policies', 1)
            ->where('retention.policies.0.id', $published->id));
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

it('lets the owning org admin assign a PUBLISHED policy, and refuses an unpublished one', function () {
    $published = RetentionPolicy::factory()->create(['is_global' => true]);
    $internal = RetentionPolicy::factory()->create();
    $package = retentionCardPackage();
    $admin = adminOf($package->organization);

    $this->actingAs($admin)
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $published->id])
        ->assertRedirect();

    expect($package->fresh()->retention_policy_id)->toBe($published->id);

    // Naming an existing but unpublished policy is an authorization refusal, not a
    // validation error — the same 403 assertCanTouchPackage() itself would answer.
    $this->actingAs($admin)
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $internal->id])
        ->assertForbidden();

    // Both halves: "refused" and "refused but applied" must not pass the same test.
    expect($package->fresh()->retention_policy_id)->toBe($published->id);
});

it('refuses a FOREIGN organization admin outright, and changes nothing', function () {
    $policy = RetentionPolicy::factory()->create(['is_global' => true]);
    $package = retentionCardPackage();

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $policy->id])
        ->assertForbidden();

    // Both halves: "refused" and "refused but applied" must not pass the same test.
    expect($package->fresh()->retention_policy_id)->toBeNull();
});

it('refuses a portal member outright — the admin console itself is closed to them', function () {
    $policy = RetentionPolicy::factory()->create(['is_global' => true]);
    $package = retentionCardPackage();
    // A Member of the package's own organization: EnsureOperator (the whole /admin group's
    // gate) refuses before the route's own assertCanTouchPackage() is ever reached — being
    // the right organization is not enough without an admin/maintainer role.
    $member = User::factory()->for($package->organization)->create(['role' => UserRole::Member]);

    $this->actingAs($member)
        ->put(route('admin.packages.retention.update', $package), ['retention_policy_id' => $policy->id])
        ->assertForbidden();

    expect($package->fresh()->retention_policy_id)->toBeNull();
});

it('lets the owning org admin set and clear inline rules', function () {
    $package = retentionCardPackage();
    $admin = adminOf($package->organization);

    $this->actingAs($admin)
        ->put(route('admin.packages.retention.update', $package), [
            'retention_rules' => [['type' => 'keep_last', 'count' => 5]],
        ])
        ->assertRedirect();

    expect($package->fresh()->retention_rules)->toEqual([['type' => 'keep_last', 'count' => 5]]);

    // Shield-only inline rules are refused for the same reason a shield-only policy is.
    $this->actingAs($admin)
        ->from(route('admin.packages.show', $package))
        ->put(route('admin.packages.retention.update', $package), [
            'retention_rules' => [['type' => 'never_delete', 'pattern' => 'prod-*']],
        ])
        ->assertSessionHasErrors('retention_rules');

    expect($package->fresh()->retention_rules)->toEqual([['type' => 'keep_last', 'count' => 5]]);

    $this->actingAs($admin)
        ->put(route('admin.packages.retention.update', $package), ['retention_rules' => null])
        ->assertRedirect();

    expect($package->fresh()->retention_rules)->toBeNull();
});

it('previews unsaved inline rules against the package itself, without saving them', function () {
    $package = retentionCardPackage();
    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'alt',
        'manifest_id' => OciManifest::factory()->for($package)->create()->id,
        'pushed_at' => '2020-01-01 00:00:00',
    ]);
    $admin = adminOf($package->organization);

    $response = $this->actingAs($admin)
        ->postJson(route('admin.packages.retention.preview', $package), [
            'retention_rules' => [['type' => 'keep_last', 'count' => 1]],
        ])
        ->assertOk();

    /** @var list<array{name: string, keep: bool}> $tags */
    $tags = $response->json('tags');

    expect($response->json('summary'))->toBe(['Letzte 1 behalten'])
        ->and(collect($tags)->firstWhere('name', 'alt')['keep'])->toBeFalse()
        // The unsaved rules are evaluated, never stored.
        ->and($package->fresh()->retention_rules)->toBeNull();
});

it('refuses a preview with invalid rules instead of guessing', function () {
    $package = retentionCardPackage();
    $admin = adminOf($package->organization);

    $this->actingAs($admin)
        ->postJson(route('admin.packages.retention.preview', $package), [
            'retention_rules' => [['type' => 'never_delete', 'pattern' => 'prod-*']],
        ])
        ->assertUnprocessable();
});

it('refuses the inline preview for a FOREIGN organization admin, and evaluates nothing', function () {
    $package = retentionCardPackage();

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->postJson(route('admin.packages.retention.preview', $package), [
            'retention_rules' => [['type' => 'keep_last', 'count' => 1]],
        ])
        ->assertForbidden();

    expect($package->fresh()->retention_rules)->toBeNull();
});

it('refuses the inline preview for a portal member outright', function () {
    $package = retentionCardPackage();
    $member = User::factory()->for($package->organization)->create(['role' => UserRole::Member]);

    $this->actingAs($member)
        ->postJson(route('admin.packages.retention.preview', $package), [
            'retention_rules' => [['type' => 'keep_last', 'count' => 1]],
        ])
        ->assertForbidden();

    expect($package->fresh()->retention_rules)->toBeNull();
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

it('refuses the per-package apply for a FOREIGN organization admin, and removes nothing', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionCardPackage();
    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'alt',
        'manifest_id' => OciManifest::factory()->for($package)->create()->id,
        'pushed_at' => '2020-01-01 00:00:00',
    ]);
    $package->update(['retention_policy_id' => $policy->id]);

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->post(route('admin.packages.retention.apply', $package))
        ->assertForbidden();

    expect($package->ociTags()->count())->toBe(2);
});

it('lets the OWNING org admin apply retention for their repository', function () {
    $policy = RetentionPolicy::factory()->create(['is_global' => true, 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
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
        ->assertRedirect();

    expect($package->ociTags()->pluck('name')->all())->toBe(['latest']);
});
