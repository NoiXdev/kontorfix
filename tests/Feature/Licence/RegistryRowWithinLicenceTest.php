<?php

// tests/Feature/Licence/RegistryRowWithinLicenceTest.php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\VersionBounds;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(superAdmin());

    $operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create();
    $this->group = Group::factory()->for($this->customer)->create();
    $this->package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
});

it('refuses a new assignment that falls wholly outside the licence', function () {
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($this->customer, $this->package, null, new VersionBounds('1.0.0', '2.9.9'));

    expect(fn () => $writer->assign($this->group, $this->package, null, new VersionBounds('3.0.0', '5.0.0')))
        ->toThrow(ValidationException::class);

    expect($this->group->packages()->count())->toBe(0);
});

it('accepts an assignment that overlaps the licence', function () {
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($this->customer, $this->package, null, new VersionBounds('1.0.0', '2.9.9'));

    $writer->assign($this->group, $this->package, null, new VersionBounds('2.0.0', '5.0.0'));

    expect($this->group->packages()->count())->toBe(1);
});

it('refuses an edit that moves an existing row outside the licence', function () {
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($this->customer, $this->package, null, new VersionBounds('1.0.0', '2.9.9'));
    $writer->assign($this->group, $this->package, null, new VersionBounds('1.0.0', '2.0.0'));

    expect(fn () => $writer->write($this->group, $this->package, null, new VersionBounds('3.0.0', '4.0.0')))
        ->toThrow(ValidationException::class);
});

it('still allows narrowing the licence under an existing row', function () {
    // The one-directional guard: a registry row outside the licence is an error, a narrowed
    // licence is a business decision whose whole point is to take effect retroactively.
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($this->customer, $this->package, null, new VersionBounds('1.0.0', '2.9.9'));
    $writer->assign($this->group, $this->package, null, new VersionBounds('1.0.0', '2.9.9'));

    $writer->writeOrganization($this->customer, $this->package, null, new VersionBounds('1.0.0', '1.9.9'));

    /** @var Package $row */
    $row = $this->customer->licensedPackages()->first();

    expect($row->pivot->version_max)->toBe('1.9.9');
});

it('leaves assignments alone when the organization holds no licence', function () {
    app(AssignmentWriter::class)->assign($this->group, $this->package, null, new VersionBounds('3.0.0', '5.0.0'));

    expect($this->group->packages()->count())->toBe(1);
});

it('refuses any assignment while the licence is expired', function () {
    $writer = app(AssignmentWriter::class);
    $writer->assignToOrganization($this->customer, $this->package, now()->subDay(), VersionBounds::unlimited());

    expect(fn () => $writer->assign($this->group, $this->package, null, VersionBounds::unlimited()))
        ->toThrow(ValidationException::class);
});
