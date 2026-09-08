<?php

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Services\Oci\Retention\RetentionPolicyResolver;

it('resolves nothing on a fresh installation', function () {
    // Both tiers ship unset — a fresh installation deletes nothing until someone says
    // otherwise, and "nothing resolved" means the runner never touches the package.
    expect(app(RetentionPolicyResolver::class)->for(Package::factory()->docker()->create()))->toBeNull();
});

it('prefers the package over the instance default', function () {
    $own = RetentionPolicy::factory()->create(['name' => 'Eigene']);
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $package = Package::factory()->docker()->create(['retention_policy_id' => $own->id]);

    expect(app(RetentionPolicyResolver::class)->for($package)?->label())->toBe('Eigene');
});

it('falls back to the instance default', function () {
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    expect(app(RetentionPolicyResolver::class)->for(Package::factory()->docker()->create())?->label())->toBe('Vorgabe');
});

it('never resolves for a package type that has no tags', function () {
    // Retention operates on OCI tags. A composer package resolving the instance default
    // would not delete anything today — it has no oci_tags rows — but the resolver saying
    // "this policy applies" to a package the feature cannot apply to is a lie every
    // surface (admin card, portal) would repeat.
    $default = RetentionPolicy::factory()->create();
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $composer = Package::factory()->create(['type' => PackageType::Composer]);

    expect(app(RetentionPolicyResolver::class)->for($composer))->toBeNull();
});

it('lets inline rules beat both the named policy and the instance default', function () {
    $own = RetentionPolicy::factory()->create(['name' => 'Eigene']);
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $package = Package::factory()->docker()->create([
        'retention_policy_id' => $own->id,
        'retention_rules' => [['type' => 'keep_last', 'count' => 3]],
    ]);

    $resolved = app(RetentionPolicyResolver::class)->for($package);

    expect($resolved?->tier)->toBe('inline')
        ->and($resolved?->policy)->toBeNull()
        // The label every surface prints for an anonymous rule set.
        ->and($resolved?->label())->toBe('Eigene Regeln (nur dieses Paket)')
        ->and($resolved?->rules[0]->count)->toBe(3);
});

it('throws on corrupt inline rules instead of guessing', function () {
    // A corrupt jsonb value must not silently read as "keep everything" (the feature stops
    // working unnoticed) nor as an empty OR (everything unshielded is removed).
    $package = Package::factory()->docker()->create([
        'retention_rules' => [['type' => 'delete_everything']],
    ]);

    expect(fn () => app(RetentionPolicyResolver::class)->for($package))
        ->toThrow(ValueError::class);
});

it('reports the tier for each named source', function () {
    $default = RetentionPolicy::factory()->create(['name' => 'Vorgabe']);
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $inheriting = Package::factory()->docker()->create();
    $naming = Package::factory()->docker()->create(['retention_policy_id' => $default->id]);

    expect(app(RetentionPolicyResolver::class)->for($inheriting)?->tier)->toBe('instance')
        ->and(app(RetentionPolicyResolver::class)->for($naming)?->tier)->toBe('package');
});
