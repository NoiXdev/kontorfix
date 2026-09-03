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

        // sync() replaces the assignment wholesale, so this endpoint expresses a DETACH by
        // omission: every assignment the submission leaves out is dropped by this write.
        // A shared assignment dropped that way is exactly the write spec §4 reserves to the
        // owning organization's administrators, and it passes the attach guard above
        // untouched — that guard only ever sees what was submitted. So the dropped set is
        // named explicitly and asked the same question.
        //
        // The two halves together make this endpoint unusable, by design, for a customer
        // admin whose registry carries a shared package: submitting it is an attach they
        // may not make, omitting it is a detach they may not make. That is the honest
        // consequence of `sync()` semantics under a two-gate rule — the alternative,
        // silently preserving the row and reporting success, would tell the caller their
        // PUT was applied when it was not. The console is unaffected: it adds with
        // syncWithoutDetaching() and detaches one row at a time.
        $this->assertMayManageSharedAssignments(
            $group->packages()->pluck('packages.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->diff($submitted)->values()->all()
        );

        // The submission IS the post-state: a PUT that swaps an own package for the shared
        // one of the same name detaches the own row in the same write and shadows nothing.
        $sharedAssignment->assertReplacementAssignable($submitted);

        $group->packages()->sync($submitted);

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }
}
