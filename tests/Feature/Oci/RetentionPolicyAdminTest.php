<?php

use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A Docker repository with one tag per entry, name => pushed_at.
 *
 * @param  array<string, string>  $tags
 */
function adminTaggedPackage(array $tags, ?RetentionPolicy $policy = null): Package
{
    $package = Package::factory()->docker()->create(['retention_policy_id' => $policy?->id]);

    foreach ($tags as $name => $pushedAt) {
        OciTag::factory()->create([
            'package_id' => $package->id,
            'name' => $name,
            'manifest_id' => OciManifest::factory()->for($package)->create()->id,
            'pushed_at' => $pushedAt,
        ]);
    }

    return $package;
}

it('lists policies with the instance-default badge', function () {
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    RetentionPolicy::factory()->create(['name' => 'Eigene']);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $this->actingAs(superAdmin())
        ->get(route('admin.retention-policies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/retention/Index')
            ->has('policies', 2)
            ->where('policies.0.is_instance_default', fn ($v) => in_array($v, [true, false], true)));
});

it('stores a policy with valid rules', function () {
    $this->actingAs(superAdmin())
        ->post(route('admin.retention-policies.store'), [
            'name' => 'Standard',
            'rules' => [
                ['type' => 'keep_last', 'count' => 10],
                ['type' => 'never_delete', 'pattern' => 'prod-*'],
            ],
        ])
        ->assertRedirect(route('admin.retention-policies.index'));

    expect(RetentionPolicy::sole()->rules)->toBe([
        ['type' => 'keep_last', 'count' => 10],
        ['type' => 'never_delete', 'pattern' => 'prod-*'],
    ]);
});

it('refuses a policy with no keep-rule', function () {
    $this->actingAs(superAdmin())
        ->from(route('admin.retention-policies.create'))
        ->post(route('admin.retention-policies.store'), [
            'name' => 'Nur Schild',
            'rules' => [['type' => 'never_delete', 'pattern' => 'prod-*']],
        ])
        ->assertSessionHasErrors('rules');

    // The refusal has to leave nothing behind, or the validation is decoration.
    expect(RetentionPolicy::count())->toBe(0);
});

it('refuses an unknown rule type, a non-positive count and an empty pattern', function (array $rules) {
    $this->actingAs(superAdmin())
        ->from(route('admin.retention-policies.create'))
        ->post(route('admin.retention-policies.store'), ['name' => 'Kaputt', 'rules' => $rules])
        ->assertSessionHasErrors('rules');

    expect(RetentionPolicy::count())->toBe(0);
})->with([
    'unknown type' => [[['type' => 'delete_everything', 'count' => 1]]],
    'zero count' => [[['type' => 'keep_last', 'count' => 0]]],
    'empty pattern' => [[['type' => 'keep_matching', 'pattern' => '']]],
    'empty list' => [[[]]],
]);

it('shows an organization admin the published policies only, read-only', function () {
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    $published = RetentionPolicy::factory()->create(['name' => 'Veröffentlicht', 'is_global' => true]);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->get(route('admin.retention-policies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/retention/Index')
            ->where('can_manage', false)
            // The unpublished instance default never appears in an org admin's list —
            // which other rule sets exist on the instance is operator configuration.
            ->has('policies', 1)
            ->where('policies.0.id', $published->id)
            ->where('policies.0.package_count', null));
});

it('stores a policy published to every organization via is_global', function () {
    $this->actingAs(superAdmin())
        ->post(route('admin.retention-policies.store'), [
            'name' => 'Global',
            'rules' => [['type' => 'keep_last', 'count' => 10]],
            'is_global' => true,
        ])
        ->assertRedirect(route('admin.retention-policies.index'));

    expect(RetentionPolicy::sole()->is_global)->toBeTrue();
});

it('refuses a non-super-admin outright', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard']);

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->put(route('admin.retention-policies.update', $policy), [
            'name' => 'Gekapert',
            'rules' => [['type' => 'keep_last', 'count' => 1]],
        ])
        ->assertForbidden();

    // Both halves: "refused" and "refused but applied" must not pass the same test.
    expect($policy->fresh()->name)->toBe('Standard');
});

it('updates a policy and keeps its name unique', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard']);
    RetentionPolicy::factory()->create(['name' => 'Belegt']);

    $this->actingAs(superAdmin())
        ->from(route('admin.retention-policies.edit', $policy))
        ->put(route('admin.retention-policies.update', $policy), [
            'name' => 'Belegt',
            'rules' => [['type' => 'keep_last', 'count' => 5]],
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs(superAdmin())
        ->put(route('admin.retention-policies.update', $policy), [
            'name' => 'Standard',
            'rules' => [['type' => 'keep_last', 'count' => 5]],
        ])
        ->assertRedirect();

    expect($policy->fresh()->rules)->toBe([['type' => 'keep_last', 'count' => 5]]);
});

it('reports the dry run with a reason per surviving tag', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    adminTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00'], $policy);

    $this->actingAs(superAdmin())
        ->get(route('admin.retention-policies.dry-run', $policy))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/retention/DryRun')
            ->where('reports.0.removed_count', 1)
            ->where('reports.0.tags.0.name', 'neu')
            ->where('reports.0.tags.0.reason', 'Letzte 1 behalten')
            ->where('reports.0.tags.1.name', 'alt')
            // null, not '': a removed tag has no reason to keep, and the page renders the
            // difference — an empty string would be a blank cell that reads like a bug.
            ->where('reports.0.tags.1.reason', null)
            ->where('totals.removed', 1)
            ->where('packages_truncated', 0));
});

it('previews unsaved rules without creating a policy', function () {
    $package = adminTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);

    $response = $this->actingAs(superAdmin())
        ->postJson(route('admin.retention-policies.preview'), [
            'package_id' => $package->id,
            'rules' => [['type' => 'keep_last', 'count' => 1]],
        ])
        ->assertOk();

    /** @var list<array{name: string, keep: bool}> $tags */
    $tags = $response->json('tags');

    expect(collect($tags)->firstWhere('name', 'alt')['keep'])->toBeFalse()
        ->and($package->ociTags()->count())->toBe(2)
        ->and(RetentionPolicy::count())->toBe(0)
        // The summary line comes from RetentionRule::describe() — the one German grammar,
        // not a TS re-implementation of it.
        ->and($response->json('summary'))->toBe(['Letzte 1 behalten']);
});

it('returns the rule summary with no package chosen yet, and evaluates no tags', function () {
    $response = $this->actingAs(superAdmin())
        ->postJson(route('admin.retention-policies.preview'), [
            'rules' => [['type' => 'keep_last', 'count' => 3]],
        ])
        ->assertOk();

    expect($response->json('summary'))->toBe(['Letzte 3 behalten'])
        ->and($response->json('tags'))->toBeNull();
});

it('refuses a preview with invalid rules instead of guessing', function () {
    $package = adminTaggedPackage(['neu' => '2026-09-07 00:00:00']);

    $this->actingAs(superAdmin())
        ->postJson(route('admin.retention-policies.preview'), [
            'package_id' => $package->id,
            'rules' => [['type' => 'keep_last', 'count' => 0]],
        ])
        ->assertUnprocessable();
});

it('applies a policy from the interface and logs it', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = adminTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00'], $policy);
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('admin.retention-policies.apply', $policy))
        ->assertRedirect();

    expect($package->ociTags()->pluck('name')->all())->toBe(['neu']);

    $entry = Activity::where('log_name', 'retention')->sole();
    // The manual run carries WHO pulled the trigger — the scheduler's entries have no
    // causer, and telling the two apart is what the column is for.
    expect($entry->causer_id)->toBe($admin->id);
});

it('refuses to delete the policy that is the instance default', function () {
    // Deleting it would nullOnDelete system_settings.retention_policy_id and silently
    // switch retention off instance-wide — refused with the remedy named, not performed
    // quietly.
    $policy = RetentionPolicy::factory()->create();
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);

    $this->actingAs(superAdmin())
        ->delete(route('admin.retention-policies.destroy', $policy))
        ->assertStatus(409);

    expect(RetentionPolicy::whereKey($policy->id)->exists())->toBeTrue()
        ->and(SystemSetting::current()->retention_policy_id)->toBe($policy->id);
});

it('deletes an unreferenced policy', function () {
    $policy = RetentionPolicy::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('admin.retention-policies.destroy', $policy))
        ->assertRedirect();

    expect(RetentionPolicy::count())->toBe(0);
});

it('accepts the instance default and the grace period on the system page', function () {
    $policy = RetentionPolicy::factory()->create();

    $this->actingAs(superAdmin())
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'retention_policy_id' => $policy->id,
            'oci_blob_grace_hours' => 48,
        ])
        ->assertRedirect();

    expect(SystemSetting::current()->retention_policy_id)->toBe($policy->id)
        ->and(SystemSetting::current()->oci_blob_grace_hours)->toBe(48);
});

it('refuses a grace period under one hour, and keeps the stored value', function () {
    $this->actingAs(superAdmin())
        ->from(route('admin.system.show'))
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'oci_blob_grace_hours' => 0,
        ])
        ->assertSessionHasErrors('oci_blob_grace_hours');

    expect(SystemSetting::current()->oci_blob_grace_hours)->toBe(24);
});

it('accepts keep_untagged as the only effective rule, but refuses two of them', function () {
    // The shield-only refusal is about inert-but-protective-looking policies; a
    // keep_untagged rule DOES something (extends untagged manifests' lifetime), so it
    // counts as effect even though it never keeps a tag.
    $this->actingAs(superAdmin())
        ->post(route('admin.retention-policies.store'), [
            'name' => 'Nur Ungetaggt',
            'rules' => [['type' => 'keep_untagged', 'days' => 14]],
        ])
        ->assertRedirect(route('admin.retention-policies.index'));

    // toEqual, not toBe: Postgres jsonb normalises key order (shorter keys first, then
    // byte order), so the stored array reads ['days', 'type'] — same pairs, different walk.
    expect(RetentionPolicy::sole()->rules)->toEqual([['type' => 'keep_untagged', 'days' => 14]]);

    // Two windows would mean a silent max() — refused instead of decided quietly.
    $this->actingAs(superAdmin())
        ->from(route('admin.retention-policies.create'))
        ->post(route('admin.retention-policies.store'), [
            'name' => 'Doppelt',
            'rules' => [
                ['type' => 'keep_untagged', 'days' => 14],
                ['type' => 'keep_untagged', 'days' => 30],
            ],
        ])
        ->assertSessionHasErrors('rules');

    expect(RetentionPolicy::count())->toBe(1);
});
