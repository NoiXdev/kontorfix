<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TokenAbility;
use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Resources\Api\RegistryTokenResource;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegistryTokenController extends Controller
{
    use ClampsPageSize, ScopesApiToUser;

    public function index(Request $request): AnonymousResourceCollection
    {
        // Registry tokens are organization credentials — only administered orgs are listed.
        $query = RegistryToken::with(['organization:id,name', 'group:id,name'])->notRevoked()->latest();
        if (! $this->seesAllOrganizations()) {
            $query->whereIn('organization_id', $this->apiUser()->administeredOrganizationIds());
        }

        return RegistryTokenResource::collection(
            $query->paginate($this->perPage($request))
        );
    }

    public function store(StoreTokenRequest $request): JsonResponse
    {
        // Only ever for an organization the caller administers (group is validated by the
        // request to belong to that same organization).
        $organizationId = $this->resolveWriteOrg($request->validated('organization_id'));

        [$token, $plain] = RegistryToken::issue(
            Organization::findOrFail($organizationId),
            $request->validated('name'),
            $request->validated('group_id') ? Group::findOrFail($request->validated('group_id')) : null,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
            $request->date('expires_at'),
        );

        $token->plain_text = $plain;

        return (new RegistryTokenResource($token))->response()->setStatusCode(201);
    }

    public function destroy(RegistryToken $registryToken): JsonResponse
    {
        $this->assertCanWriteOrg($registryToken->organization_id);

        $registryToken->delete();

        return response()->json(status: 204);
    }
}
