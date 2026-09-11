<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignmentBoundsRequest;
use App\Models\Group;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\VersionBounds;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The package page's "Freigaben" tab: every one of a package's cross-registry
 * assignments, created, edited and ended from the PACKAGE side rather than the registry
 * side {@see GroupController} already offers those same three
 * writes from.
 *
 * Every method here does exactly one thing: resolve the request into the shape
 * {@see AssignmentWriter}'s `assign()`, `write()` and `revoke()` take, then call straight
 * through. Nothing here re-asks any of the questions that class's own docblock lists —
 * whether a shared package's owning organization is who the caller administers, the
 * same-name shadow guard, or the type-aware bounds validation — because those three
 * methods ask every one of them themselves, in the same order, for both this controller
 * and `GroupController`. A refusal here is always the writer's
 * `abort_if`/`abort_unless`/`ValidationException` bubbling straight up, never a second,
 * possibly-drifting copy of the same rule.
 *
 * The one question this controller DOES ask a second time: update() and destroy() call
 * {@see ScopesToAdministeredOrgs::assertAdministersGroupInScope()}
 * themselves, BEFORE checking whether the named assignment row exists at all — see
 * update()'s own docblock for why that ordering, not merely asking it once inside the
 * writer, is what keeps a 404 from leaking whether a shared package is assigned to a
 * registry the caller may not administer.
 */
class PackageAssignmentController extends Controller
{
    use ScopesToAdministeredOrgs;

    /**
     * A brand new assignment — the "Registry freigeben" picker's submit. `group_id` names
     * the target registry in the request body rather than the route, because unlike
     * update()/destroy() the row does not exist yet for a route segment to identify.
     *
     * The bounds shape mirrors {@see AssignmentBoundsRequest} (the same three columns,
     * `available_until` a plain day, `version_min`/`version_max` nullable strings) without
     * reusing that class directly: its docblock scopes it to an EXISTING assignment's
     * partial-edit semantics (`sometimes`, merged against what is already stored), which
     * has no stored value to merge against here — an omitted bound on a brand new row
     * means "no bound", not "keep whatever was there".
     */
    public function store(Request $request, Package $package, AssignmentWriter $writer): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'uuid', 'exists:groups,id'],
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'version_min' => ['nullable', 'string', 'max:190'],
            'version_max' => ['nullable', 'string', 'max:190'],
        ]);

        $writer->assign(
            Group::findOrFail($data['group_id']),
            $package,
            // Through the END of the named day — see AssignmentWriter's sibling call in
            // update() below for why.
            $data['available_until'] === null ? null : CarbonImmutable::parse($data['available_until'])->endOfDay(),
            new VersionBounds($data['version_min'] ?? null, $data['version_max'] ?? null),
        );

        return back()->with('success', 'Registry freigegeben.');
    }

    /**
     * Bounds/period on an assignment that already exists.
     *
     * Same 403-before-404 ordering `GroupController::updateAssignment()` pins and explains
     * in its own docblock: `assertAdministersGroupInScope()` is asked BEFORE the existence
     * check, so a caller who may not administer the TARGET registry at all gets the same
     * 403 whether or not a (package, group) assignment row exists there. Asking existence
     * first — as this method used to — let exactly that caller distinguish a 404 (no row)
     * from a 403 (a row exists, refused on some other ground) and thereby learn, from the
     * status code alone, whether a given customer holds a given shared package. Reachable
     * with nothing more than a group UUID, which is why the group-scope question has to
     * come first here as it already does on the registry side.
     *
     * `AssignmentBoundsRequest`'s bounds fields are `sometimes`: an omitted side is merged
     * with whatever is already stored (`$request->has()`, not `filled()` or `??`, is what
     * tells "omitted" apart from "submitted null" — see that class's docblock). This
     * merged, EFFECTIVE pair — never the raw request in isolation — is what
     * {@see AssignmentWriter::write()} validates and persists; see its docblock for the
     * incident that shape of bug produced before the writer took over validating it.
     */
    public function update(
        AssignmentBoundsRequest $request,
        Package $package,
        Group $group,
        AssignmentWriter $writer,
        VersionEntitlement $entitlement,
    ): RedirectResponse {
        $this->assertAdministersGroupInScope($group);

        // Without this the request would silently succeed against no row at all —
        // AssignmentWriter::write()'s updateExistingPivot() reports zero affected rows and
        // returns. write() asks assertAdministersGroupInScope() again afterwards, which is
        // redundant with the call above, not a behavior change — see this method's own
        // docblock for why that call has to run first.
        abort_unless($group->packages()->whereKey($package->id)->exists(), 404);

        $data = $request->validated();
        $current = $entitlement->boundsFor($group, $package);

        $writer->write(
            $group,
            $package,
            $data['available_until'] === null ? null : CarbonImmutable::parse($data['available_until'])->endOfDay(),
            new VersionBounds(
                $request->has('version_min') ? $data['version_min'] : $current->min,
                $request->has('version_max') ? $data['version_max'] : $current->max,
            ),
        );

        return back()->with('success', 'Freigabe aktualisiert.');
    }

    /**
     * Ends an assignment outright — the pivot row disappears. Same 403-before-404 ordering
     * as update(): the target registry's scope is asked first, so a pair with no
     * assignment row answers with the same 403 a caller outside that scope would get if
     * the row existed, rather than leaking existence via 404.
     */
    public function destroy(Package $package, Group $group, AssignmentWriter $writer): RedirectResponse
    {
        $this->assertAdministersGroupInScope($group);

        abort_unless($group->packages()->whereKey($package->id)->exists(), 404);

        $writer->revoke($group, $package);

        return back()->with('success', 'Freigabe entfernt.');
    }
}
