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

        $this->assertCanAttachPackages($validated['package_ids'] ?? [], $group->organization_id);
        // sync() replaces the assignment wholesale, so the submission IS the post-state:
        // a PUT that swaps an own package for the shared one of the same name detaches the
        // own row in the same write and shadows nothing.
        $sharedAssignment->assertReplacementAssignable($validated['package_ids'] ?? []);

        $group->packages()->sync($validated['package_ids'] ?? []);

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }
}
