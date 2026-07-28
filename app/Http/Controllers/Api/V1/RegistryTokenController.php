<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TokenAbility;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        return RegistryTokenResource::collection(
            RegistryToken::with(['organization:id,name', 'group:id,name'])->latest()->paginate(
                min((int) $request->query('per_page', 25), 100)
            )
        );
    }

    public function store(StoreTokenRequest $request): JsonResponse
    {
        [$token, $plain] = RegistryToken::issue(
            Organization::findOrFail($request->validated('organization_id')),
            $request->validated('name'),
            $request->validated('group_id') ? Group::findOrFail($request->validated('group_id')) : null,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
        );

        $token->plain_text = $plain;

        return (new RegistryTokenResource($token))->response()->setStatusCode(201);
    }

    public function destroy(RegistryToken $registryToken): JsonResponse
    {
        $registryToken->delete();

        return response()->json(status: 204);
    }
}
