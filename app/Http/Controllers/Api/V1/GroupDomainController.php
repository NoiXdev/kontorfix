<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Models\Domain;
use App\Models\Group;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiGroup('Registries')]
class GroupDomainController extends Controller
{
    use ScopesApiToUser;

    /** Domains einer Registry auflisten. */
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->assertCanReadGroup($group);

        return DomainResource::collection($group->domains);
    }

    /**
     * Domain zu einer Registry hinzufügen.
     *
     * Nur für Super-Admins — ein Hostname ist instanzweit eindeutig und lässt sich innerhalb
     * der Anwendung nicht verifizieren.
     */
    public function store(StoreDomainRequest $request, Group $group): JsonResponse
    {
        $this->assertCanWriteGroup($group);

        $domain = $group->domains()->create(['hostname' => $request->validated('hostname')]);

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    /** Domain von einer Registry entfernen. */
    public function destroy(Group $group, Domain $domain): JsonResponse
    {
        $this->assertCanWriteGroup($group);
        abort_unless($domain->group_id === $group->id, 404);
        $domain->delete();

        return response()->json(status: 204);
    }
}
