<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\RegistryAccessService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->svc = app(RegistryAccessService::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->groupA1 = Group::factory()->for($this->orgA)->create();
    $this->groupA2 = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();
});

describe('canAccessOrganization', function () {
    it('grants an org-wide token access to its own organization', function () {
        [, $plain] = RegistryToken::issue($this->orgA, 'a', group: null);
        $token = RegistryToken::findByPlainText($plain);

        expect($this->svc->canAccessOrganization($token, $this->orgA))->toBeTrue();
    });

    it('denies a group-scoped token of the same organization', function () {
        [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA1);
        $token = RegistryToken::findByPlainText($plain);

        expect($this->svc->canAccessOrganization($token, $this->orgA))->toBeFalse();
    });

    it('denies an org-wide token of a foreign organization', function () {
        [, $plain] = RegistryToken::issue($this->orgB, 'a', group: null);
        $token = RegistryToken::findByPlainText($plain);

        expect($this->svc->canAccessOrganization($token, $this->orgA))->toBeFalse();
    });

    it('denies a null token', function () {
        expect($this->svc->canAccessOrganization(null, $this->orgA))->toBeFalse();
    });

    it('does not open anonymous access just because one group of the org is public', function () {
        // Spec decision 4: canAccessOrganization has NO public shortcut, unlike
        // canAccessGroup() — the aggregate spans private groups too, so a single public
        // group in the org must not leak org-wide anonymous access.
        $this->groupA1->update(['public' => true]);

        expect($this->svc->canAccessOrganization(null, $this->orgA))->toBeFalse();
    });

    /**
     * Same defensive concern as canAccessGroup()'s equivalent case: organization_id is
     * database-enforced NOT NULL for a persisted row, but this method takes plain models
     * rather than a guaranteed database round trip. Built in memory (never saved), which
     * is the only way to construct an unset id on either side, this proves the guard still
     * refuses rather than letting two unset ids compare equal.
     */
    it('never grants organization access when both sides ids are unset', function () {
        $ownerlessOrganization = Organization::factory()->make(['id' => null]);
        $orgWideToken = new RegistryToken(['organization_id' => null, 'group_id' => null]);

        expect($this->svc->canAccessOrganization($orgWideToken, $ownerlessOrganization))->toBeFalse();
    });
});

describe('packagesForOrganization', function () {
    it('unions distinct packages assigned across all of the organization groups', function () {
        $pkg1 = Package::factory()->inOrgOf($this->groupA1)->create();
        $pkg2 = Package::factory()->inOrgOf($this->groupA2)->create();
        $this->groupA1->packages()->attach($pkg1);
        $this->groupA2->packages()->attach($pkg2);

        $ids = $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        expect($ids)->toContain($pkg1->id, $pkg2->id)->toHaveCount(2);
    });

    it('deduplicates a package assigned to more than one group of the same organization', function () {
        $pkg = Package::factory()->inOrgOf($this->groupA1)->create();
        $this->groupA1->packages()->attach($pkg);
        $this->groupA2->packages()->attach($pkg);

        $ids = $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        expect($ids->filter(fn ($id) => $id === $pkg->id))->toHaveCount(1);
    });

    it('excludes an expired assignment', function () {
        $pkg = Package::factory()->inOrgOf($this->groupA1)->create();
        $this->groupA1->packages()->attach($pkg, ['available_until' => now()->subDay()]);

        $ids = $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        expect($ids)->not->toContain($pkg->id);
    });

    it('includes a shared operator package assigned to a group of the organization', function () {
        $operator = Organization::factory()->create(['is_operator' => true]);
        $shared = Package::factory()->for($operator)->create(['shared' => true]);
        $this->groupA1->packages()->attach($shared);

        $ids = $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        expect($ids)->toContain($shared->id);
    });

    it('excludes a package assigned only to a group of another organization', function () {
        $pkgB = Package::factory()->inOrgOf($this->groupB)->create();
        $this->groupB->packages()->attach($pkgB);

        $ids = $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        expect($ids)->not->toContain($pkgB->id);
    });

    it('runs the union as a single query', function () {
        $pkg1 = Package::factory()->inOrgOf($this->groupA1)->create();
        $pkg2 = Package::factory()->inOrgOf($this->groupA2)->create();
        $this->groupA1->packages()->attach($pkg1);
        $this->groupA2->packages()->attach($pkg2);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->svc->packagesForOrganization($this->orgA)->pluck('id');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queryCount)->toBe(1);
    });
});

describe('organizationPackage', function () {
    it('finds a package visible through one of the organization groups', function () {
        $pkg = Package::factory()->inOrgOf($this->groupA1)->create(['type' => PackageType::Npm, 'name' => 'acme/widget']);
        $this->groupA1->packages()->attach($pkg);

        $found = $this->svc->organizationPackage($this->orgA, PackageType::Npm, 'acme/widget');

        expect($found?->id)->toBe($pkg->id);
    });

    it('returns null when the package exists but is not assigned to any group of the organization', function () {
        Package::factory()->inOrgOf($this->groupA1)->create(['type' => PackageType::Npm, 'name' => 'acme/invisible']);
        // Deliberately never attached to any group.

        $found = $this->svc->organizationPackage($this->orgA, PackageType::Npm, 'acme/invisible');

        expect($found)->toBeNull();
    });

    it('returns null when no such package exists at all', function () {
        $found = $this->svc->organizationPackage($this->orgA, PackageType::Npm, 'acme/does-not-exist');

        expect($found)->toBeNull();
    });
});
