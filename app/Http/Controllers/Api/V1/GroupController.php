<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Http\Resources\Api\GroupResource;
use App\Models\Group;
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

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $organizationId = $this->resolveWriteOrg($request->validated('organization_id'));

        // Never let a registry be seeded with another organization's packages.
        $packageIds = $request->validated('package_ids', []);
        $this->assertCanAttachPackages($packageIds, $organizationId);

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

        $group->update(['name' => $request->validated('name'), 'public' => $request->boolean('public')]);

        return new GroupResource($group);
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->assertCanWriteGroup($group);

        $group->delete();

        return response()->json(status: 204);
    }
}
