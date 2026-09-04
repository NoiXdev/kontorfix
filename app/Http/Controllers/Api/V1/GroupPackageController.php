<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Group;
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

        return PackageResource::collection($group->packages()->orderBy('name')->get());
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

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }
}
