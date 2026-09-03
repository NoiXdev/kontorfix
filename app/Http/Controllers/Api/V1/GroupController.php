<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Http\Resources\Api\GroupResource;
use App\Models\Group;
use App\Services\Package\SharedAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupController extends Controller
{
    use ClampsPageSize, ScopesApiToUser;

    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(
            $this->scopeGroupRead(Group::query())->orderBy('name')->paginate($this->perPage($request))
        );
    }

    public function show(Group $group): GroupResource
    {
        $this->assertCanReadGroup($group);

        return new GroupResource($group);
    }

    public function store(StoreGroupRequest $request, SharedAssignment $sharedAssignment): JsonResponse
    {
        $organizationId = $this->resolveWriteOrg($request->validated('organization_id'));

        // Never let a registry be seeded with another organization's packages.
        $packageIds = $request->validated('package_ids', []);
        $this->assertCanAttachPackages($packageIds, $organizationId);

        // …and never leaving the registry serving a shared package beside an own one of
        // the same name. The registry starts empty, so its post-state is the submission.
        // Before the insert, so a refusal leaves no empty registry behind.
        $sharedAssignment->assertReplacementAssignable($packageIds);

        $group = Group::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'public' => $request->boolean('public'),
            'organization_id' => $organizationId,
        ]);
        $group->packages()->sync($packageIds);

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $this->assertCanWriteGroup($group);

        // The slug only when the caller actually sent one — a PUT that names just the fields
        // it wants changed keeps leaving the registry's address alone. Validating it here and
        // then dropping it would leave UnclaimedSlug enforced on the console only, which is
        // precisely the half-enforced invariant it exists to close.
        $group->update([
            'name' => $request->validated('name'),
            'public' => $request->boolean('public'),
            ...$request->safe()->only('slug'),
        ]);

        return new GroupResource($group);
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->assertCanWriteGroup($group);

        $group->delete();

        return response()->json(status: 204);
    }
}
