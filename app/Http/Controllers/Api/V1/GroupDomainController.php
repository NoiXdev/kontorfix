<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupDomainController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        return DomainResource::collection($group->domains);
    }

    public function store(StoreDomainRequest $request, Group $group): JsonResponse
    {
        $domain = $group->domains()->create(['hostname' => $request->validated('hostname')]);

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function destroy(Group $group, Domain $domain): JsonResponse
    {
        abort_unless($domain->group_id === $group->id, 404);
        $domain->delete();

        return response()->json(status: 204);
    }
}
