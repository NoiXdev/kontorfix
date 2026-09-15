<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrganizationLicenceRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\OrganizationLicence;
use App\Support\Licence\VersionBounds;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The one write surface behind BOTH licence entry points — the "Organisationen" block on
 * the package page and the "Lizenzierte Pakete" section on the customer page. Two hosts,
 * one controller, one writer: the alternative was two controllers that would agree today
 * and drift apart at the first change.
 *
 * Every method resolves the request into the shape AssignmentWriter's three org methods
 * take and calls straight through, re-asking none of their guards — see
 * PackageAssignmentController, which takes the same position for registry assignments and
 * explains why a refusal must be the writer's, never a second copy of the same rule.
 */
class OrganizationPackageController extends Controller
{
    use ScopesToAdministeredOrgs;

    /**
     * What a proposed licence window would do to the registries that already carry the
     * package — computed, not guessed, and computed HERE rather than in the browser.
     *
     * The spec argues the one-directional guard (a registry row outside the licence is
     * refused; narrowing the licence afterwards is allowed and retroactive) is acceptable
     * BECAUSE the console shows the consequence before saving. This is that half.
     *
     * Server-side deliberately. A preview that compared versions in TypeScript would be a
     * second statement of an ecosystem-aware rule, and the last one disagreed with the
     * server on its first real case — rating `2.0.0-beta1` equal to `2.0.0` and truncating
     * PEP 440 forms at the first non-digit. A preview the operator cannot trust is worse
     * than none, because they act on it. This runs through the very method that later
     * decides for real, so the two cannot diverge.
     *
     * Writes nothing.
     */
    public function preview(Request $request, Organization $organization, VersionEntitlement $entitlement): JsonResponse
    {
        $this->assertAdministersOrgInScope($organization->getKey());

        $data = $request->validate([
            'package_id' => ['required', 'uuid', 'exists:packages,id'],
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'version_min' => ['nullable', 'string', 'max:190'],
            'version_max' => ['nullable', 'string', 'max:190'],
        ]);

        $package = Package::findOrFail($data['package_id']);

        $proposed = new OrganizationLicence(
            new VersionBounds($data['version_min'] ?? null, $data['version_max'] ?? null),
            $data['available_until'] === null ? null : CarbonImmutable::parse($data['available_until'])->endOfDay(),
        );

        $registries = $organization->groups()
            ->whereHas('packages', fn ($q) => $q->whereKey($package->getKey()))
            ->orderBy('name')
            ->get()
            ->map(function (Group $group) use ($package, $proposed, $entitlement): array {
                $stored = $entitlement->storedBoundsFor($group, $package) ?? VersionBounds::unlimited();
                $effective = $entitlement->applyLicence($proposed, $stored, $package->type);

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'emptied' => $effective === null,
                    'narrowed' => $effective !== null
                        && ($effective->min !== $stored->min || $effective->max !== $stored->max),
                    'effective_version_min' => $effective?->min,
                    'effective_version_max' => $effective?->max,
                ];
            })
            ->values();

        return response()->json([
            'registries' => $registries,
            'narrowed_count' => $registries->where('narrowed', true)->count(),
            'emptied_count' => $registries->where('emptied', true)->count(),
        ]);
    }

    public function store(Request $request, Organization $organization, AssignmentWriter $writer): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'uuid', 'exists:packages,id'],
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'version_min' => ['nullable', 'string', 'max:190'],
            'version_max' => ['nullable', 'string', 'max:190'],
        ]);

        $writer->assignToOrganization(
            $organization,
            Package::findOrFail($data['package_id']),
            // Through the END of the named day, as registry assignments already read it.
            $data['available_until'] === null ? null : CarbonImmutable::parse($data['available_until'])->endOfDay(),
            new VersionBounds($data['version_min'] ?? null, $data['version_max'] ?? null),
        );

        return back()->with('success', 'Lizenz erteilt.');
    }

    /**
     * Same 403-before-404 ordering PackageAssignmentController::update() pins: the target
     * organization's scope is asked BEFORE the existence check, so a caller who may not
     * administer it cannot tell a missing licence from a refused one by status code, and
     * thereby learn which customer holds which shared package.
     */
    public function update(
        OrganizationLicenceRequest $request,
        Organization $organization,
        Package $package,
        AssignmentWriter $writer,
        VersionEntitlement $entitlement,
    ): RedirectResponse {
        $this->assertAdministersOrgInScope($organization->getKey());

        $current = $entitlement->organizationLicence($organization->getKey(), $package);
        abort_if($current === null, 404);

        $data = $request->validated();

        $writer->writeOrganization(
            $organization,
            $package,
            $data['available_until'] === null ? null : CarbonImmutable::parse($data['available_until'])->endOfDay(),
            new VersionBounds(
                $request->has('version_min') ? $data['version_min'] : $current->bounds->min,
                $request->has('version_max') ? $data['version_max'] : $current->bounds->max,
            ),
        );

        return back()->with('success', 'Lizenz aktualisiert.');
    }

    public function destroy(Organization $organization, Package $package, AssignmentWriter $writer, VersionEntitlement $entitlement): RedirectResponse
    {
        $this->assertAdministersOrgInScope($organization->getKey());

        abort_if($entitlement->organizationLicence($organization->getKey(), $package) === null, 404);

        $writer->revokeOrganization($organization, $package);

        return back()->with('success', 'Lizenz entfernt.');
    }
}
