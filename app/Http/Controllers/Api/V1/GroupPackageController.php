<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Package;
use App\Services\Package\SharedAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupPackageController extends Controller
{
    use ScopesApiToUser;

    public function index(Group $group): AnonymousResourceCollection
    {
        $this->assertCanReadGroup($group);

        return $this->assignments($group);
    }

    public function update(Request $request, Group $group, SharedAssignment $sharedAssignment): AnonymousResourceCollection
    {
        $this->assertCanWriteGroup($group);

        $validated = $request->validate([
            'package_ids' => ['array'],
            'package_ids.*' => ['uuid', Rule::exists('packages', 'id')],
        ]);

        $submitted = $validated['package_ids'] ?? [];

        $this->assertCanAttachPackages($submitted, $group->organization_id);

        // sync() replaces the assignment wholesale, so the submission IS the post-state — and
        // therefore this endpoint expresses a DETACH BY OMISSION: an assignment the caller
        // leaves out is dropped by this write. Nothing about that reaches the attach guard
        // above, which only ever sees what WAS submitted.
        //
        // Both directions are one comparison here rather than two rules, which is what keeps
        // the endpoint usable: a customer admin who re-sends the shared package alongside
        // their own leaves the shared assignments exactly as they were and is not refused,
        // while adding one, dropping one or swapping one for another is.
        $this->assertSharedAssignmentsUnchanged($this->currentAssignmentIds($group), $submitted);

        // The same post-state, asked the orthogonal shadowing question: a PUT that swaps an
        // own package for the shared one of the same name detaches the own row in the same
        // write and shadows nothing.
        $sharedAssignment->assertReplacementAssignable($submitted);

        $group->packages()->sync($submitted);

        return $this->assignments($group);
    }

    /**
     * Every assignment row this registry carries, each marked with whether the registry
     * actually serves it today.
     *
     * `packages()`, NOT `assignedPackages()`, and unlike the customer portal, which swapped
     * to the in-force relation for exactly the defect this method also fixes. The difference
     * is what the two lists are for: the portal's is a display, this one is the input to
     * {@see update()}, whose `sync()` is a DETACH BY OMISSION. A client that GETs this list,
     * adds an id and PUTs it back — the ordinary way to use these two endpoints — would drop
     * every row this list omitted. Hiding the lapsed ones would therefore make a read-modify-
     * write silently detach them, and detaching is the one act that releases a shared name
     * back to the public index (spec §4 as amended: an explicit act opens the fallthrough,
     * the passage of time does not). A lapsed row that is merely unmarked is a reporting
     * defect; a lapsed row that vanishes turns a read into a destructive write.
     *
     * So the rows stay and are marked instead, with the same two fields the admin console
     * shows: the date, and `in_force` decided by {@see Group::assignedPackages()}
     * — the single statement of the expiry predicate — rather than by comparing dates here.
     */
    private function assignments(Group $group): AnonymousResourceCollection
    {
        $inForce = $group->assignedPackages()->pluck('packages.id')->all();

        $rows = $group->packages()->orderBy('name')->get()
            ->map(function (Package $p) use ($inForce): PackageResource {
                // Read via getRelation() rather than `$p->pivot`, which the belongsToMany
                // sets dynamically and so is invisible to static analysis on a plain Package.
                $pivot = $p->relationLoaded('pivot') ? $p->getRelation('pivot') : null;

                return (new PackageResource($p))->withAssignment(
                    $pivot instanceof GroupPackage ? $pivot->available_until?->toIso8601String() : null,
                    in_array($p->id, $inForce, true),
                );
            });

        return PackageResource::collection($rows);
    }
}
