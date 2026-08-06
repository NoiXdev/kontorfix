<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUpstreamRequest;
use App\Http\Resources\Api\UpstreamResource;
use App\Models\Group;
use App\Models\Upstream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupUpstreamController extends Controller
{
    use ScopesApiToUser;

    public function index(Group $group): AnonymousResourceCollection
    {
        $this->assertCanReadGroup($group);

        return UpstreamResource::collection($group->upstreams);
    }

    public function store(StoreUpstreamRequest $request, Group $group): JsonResponse
    {
        $this->assertCanWriteGroup($group);

        $data = $request->validated();

        $upstream = $group->upstreams()->create([
            'type' => $data['type'],
            'url' => $data['url'],
            'policy' => $data['policy'],
            'auth_token' => $data['auth_token'] ?? null ?: null,
            'priority' => $data['priority'] ?? 0,
        ]);

        foreach ($data['allowed_packages'] ?? [] as $name) {
            $upstream->allowedPackages()->create(['name' => $name]);
        }

        return (new UpstreamResource($upstream))->response()->setStatusCode(201);
    }

    public function destroy(Group $group, Upstream $upstream): JsonResponse
    {
        $this->assertCanWriteGroup($group);
        abort_unless($upstream->group_id === $group->id, 404);
        $upstream->delete();

        return response()->json(status: 204);
    }
}
