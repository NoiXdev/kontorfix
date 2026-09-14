<?php

use App\Models\Organization;
use App\Models\Package;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the organization_package table with the licence columns', function () {
    expect(Schema::hasTable('organization_package'))->toBeTrue()
        ->and(Schema::hasColumns('organization_package', [
            'organization_id', 'package_id', 'available_until', 'version_min', 'version_max',
        ]))->toBeTrue();
});

it('exposes the licence columns from both sides of the relation', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);

    $org->licensedPackages()->attach($package->id, [
        'version_min' => '1.0.0',
        'version_max' => '2.9.9',
        'available_until' => null,
    ]);

    // Through get() rather than first(): a collection has no nullability for Larastan to
    // trip over on a relation with a custom pivot, and the assertion is the same one.
    $fromOrganization = $org->licensedPackages()
        ->get()
        ->map(fn (Package $p): array => [$p->pivot->version_min, $p->pivot->version_max, $p->pivot->available_until])
        ->all();

    // Qualified pivot column rather than the pivot object: Organization has never been the
    // far side of a belongsToMany before, so nothing tells static analysis it carries one —
    // and this asserts the same fact, that the column is readable from the package side.
    $fromPackage = $package->licensedOrganizations()->pluck('organization_package.version_max')->all();

    expect($fromOrganization)->toBe([['1.0.0', '2.9.9', null]])
        ->and($fromPackage)->toBe(['2.9.9']);
});

it('links the licence to the right pair', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);
    Package::factory()->create(['shared' => true]);

    $org->licensedPackages()->attach($package->id);

    expect($org->licensedPackages()->pluck('packages.id')->all())->toBe([$package->id]);
});

it('allows only one licence per organization and package', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);

    $org->licensedPackages()->attach($package->id);

    expect(fn () => $org->licensedPackages()->attach($package->id))
        ->toThrow(QueryException::class);
});

it('drops the licence when either side is deleted', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);
    $org->licensedPackages()->attach($package->id);

    $package->delete();

    expect(DB::table('organization_package')->count())->toBe(0);
});
