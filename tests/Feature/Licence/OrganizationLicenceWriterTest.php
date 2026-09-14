<?php

// tests/Feature/Licence/OrganizationLicenceWriterTest.php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\VersionBounds;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  array<string, mixed>  $packageAttributes
 * @return array{Organization, Package}
 */
function licenceWriterFixture(array $packageAttributes = []): array
{
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(array_merge(
        ['shared' => true, 'type' => PackageType::Composer],
        $packageAttributes,
    ));

    return [$customer, $package];
}

beforeEach(function () {
    $this->actingAs(superAdmin());
});

it('creates a licence', function () {
    [$customer, $package] = licenceWriterFixture();

    app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, new VersionBounds('1.0.0', '2.9.9'),
    );

    /** @var Package $row */
    $row = $customer->licensedPackages()->first();

    expect($row->pivot->version_min)->toBe('1.0.0')->and($row->pivot->version_max)->toBe('2.9.9');
});

it('updates an existing licence', function () {
    [$customer, $package] = licenceWriterFixture();
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($customer, $package, null, new VersionBounds('1.0.0', '2.9.9'));

    $writer->writeOrganization($customer, $package, null, new VersionBounds('1.0.0', '1.9.9'));

    /** @var Package $row */
    $row = $customer->licensedPackages()->first();

    expect($row->pivot->version_max)->toBe('1.9.9');
});

it('revokes a licence', function () {
    [$customer, $package] = licenceWriterFixture();
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($customer, $package, null, VersionBounds::unlimited());

    $writer->revokeOrganization($customer, $package);

    expect($customer->licensedPackages()->count())->toBe(0);
});

it('refuses a package that is not shared', function () {
    [$customer, $package] = licenceWriterFixture(['shared' => false]);

    expect(fn () => app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, VersionBounds::unlimited(),
    ))->toThrow(ValidationException::class);

    expect($customer->licensedPackages()->count())->toBe(0);
});

it('refuses a docker package', function () {
    [$customer, $package] = licenceWriterFixture(['type' => PackageType::Docker]);

    expect(fn () => app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, new VersionBounds('1.0.0', '2.0.0'),
    ))->toThrow(ValidationException::class);
});

it('refuses a docker package even with unlimited bounds', function () {
    [$customer, $package] = licenceWriterFixture(['type' => PackageType::Docker]);

    expect(fn () => app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, VersionBounds::unlimited(),
    ))->toThrow(ValidationException::class);

    expect($customer->licensedPackages()->count())->toBe(0);
});

it('refuses an unordered window', function () {
    [$customer, $package] = licenceWriterFixture();

    expect(fn () => app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, new VersionBounds('3.0.0', '1.0.0'),
    ))->toThrow(ValidationException::class);
});

it('refuses a caller who administers neither organization', function () {
    [$customer, $package] = licenceWriterFixture();
    $outsider = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider);

    expect(fn () => app(AssignmentWriter::class)->assignToOrganization(
        $customer, $package, null, VersionBounds::unlimited(),
    ))->toThrow(HttpException::class);
});
