<?php

namespace App\Http\Controllers\Api\V1;

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
    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(
            Group::orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Group $group): GroupResource
    {
        return new GroupResource($group);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'public' => $request->boolean('public'),
            'organization_id' => $request->validated('organization_id') ?? $request->user()->organization_id,
        ]);
        $group->packages()->sync($request->validated('package_ids', []));

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $group->update(['name' => $request->validated('name'), 'public' => $request->boolean('public')]);

        return new GroupResource($group);
    }

    public function destroy(Group $group): JsonResponse
    {
        $group->delete();

        return response()->json(status: 204);
    }
}
