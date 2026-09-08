<?php

use App\Enums\PackageType;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('holds a named, reusable rule set', function () {
    $policy = RetentionPolicy::create([
        'name' => 'Standard',
        'rules' => [['type' => 'keep_last', 'count' => 10]],
    ]);

    // jsonb round-trips as a PHP array, not as a string.
    expect($policy->fresh()->rules)->toBe([['type' => 'keep_last', 'count' => 10]]);
});

it('refuses two policies with the same name', function () {
    RetentionPolicy::create(['name' => 'Standard', 'rules' => []]);

    expect(fn () => RetentionPolicy::create(['name' => 'Standard', 'rules' => []]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lets a package point at a policy and survives the policy being deleted', function () {
    $policy = RetentionPolicy::create(['name' => 'Standard', 'rules' => []]);
    $package = Package::factory()->create(['retention_policy_id' => $policy->id]);

    $policy->delete();

    // nullOnDelete, not cascade: deleting a cleanup rule must never delete a repository.
    expect($package->fresh()->retention_policy_id)->toBeNull()
        ->and(Package::whereKey($package->id)->exists())->toBeTrue();
});

it('lets the instance default point at a policy and ships unset', function () {
    expect(SystemSetting::current()->retention_policy_id)->toBeNull()
        ->and(SystemSetting::current()->oci_blob_grace_hours)->toBe(24);

    $policy = RetentionPolicy::create(['name' => 'Standard', 'rules' => []]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);

    expect(SystemSetting::current()->retentionPolicy->name)->toBe('Standard');
});

it('survives the instance default policy being deleted', function () {
    $policy = RetentionPolicy::create(['name' => 'Vorgabe', 'rules' => []]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);

    $policy->delete();

    // The settings row must not go with it. Retention switches itself off, which is the
    // reason the controller refuses this delete in the first place — but the schema has to
    // survive it happening through any other writer.
    expect(SystemSetting::current()->retention_policy_id)->toBeNull();
});

it('defaults pushed_at at the database, not only in the factory', function () {
    $tag = OciTag::factory()->create();

    // A raw insert with NO pushed_at at all. This is what proves the column's own default
    // and NOT NULL; the factory's own value would hide both.
    $id = (string) Str::uuid();
    DB::table('oci_tags')->insert([
        'id' => $id,
        'package_id' => $tag->package_id,
        'name' => 'ohne-stempel',
        'manifest_id' => $tag->manifest_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('oci_tags')->where('id', $id)->value('pushed_at'))->not->toBeNull();

    expect(fn () => DB::table('oci_tags')->where('id', $id)->update(['pushed_at' => null]))
        ->toThrow(QueryException::class);
});

it('marks a package as push-created without touching created_at', function () {
    $package = Package::factory()->create(['auto_created_at' => now()]);

    expect($package->fresh()->auto_created_at)->not->toBeNull()
        // The distinction the sweeper depends on: an ordinary create leaves it null.
        ->and(Package::factory()->create()->auto_created_at)->toBeNull();
});

it('gives the factory a Docker state that leaves the organization alone', function () {
    $organization = Organization::factory()->create();

    $package = Package::factory()->for($organization)->docker()->create();

    expect($package->type)->toBe(PackageType::Docker)
        // docker() must not touch organization_id, or it would silently defeat the scoping
        // the OCI tenancy tests are built on.
        ->and($package->organization_id)->toBe($organization->id);
});

it('indexes what the sweeper and keep_last actually scan', function () {
    expect(Schema::hasColumn('oci_tags', 'pushed_at'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'auto_created_at'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'retention_policy_id'))->toBeTrue()
        ->and(Schema::hasColumn('system_settings', 'retention_policy_id'))->toBeTrue()
        ->and(Schema::hasColumn('system_settings', 'oci_blob_grace_hours'))->toBeTrue();

    $tagIndexes = collect(Schema::getIndexes('oci_tags'))->pluck('columns');
    expect($tagIndexes)->toContain(['package_id', 'pushed_at']);

    $packageIndexes = collect(Schema::getIndexes('packages'))->pluck('columns');
    expect($packageIndexes)->toContain(['auto_created_at']);
});
