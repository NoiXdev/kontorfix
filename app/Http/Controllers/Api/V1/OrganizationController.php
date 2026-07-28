<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Resources\Api\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $org = Organization::create([...$request->validated(), 'is_operator' => false]);

        return (new OrganizationResource($org))->response()->setStatusCode(201);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        if ($organization->is_operator || $organization->users()->exists() || $organization->groups()->exists()) {
            throw ValidationException::withMessages([
                'organization' => 'Organisation ist Betreiber oder nicht leer (erst Registries/Nutzer entfernen).',
            ]);
        }

        $organization->delete();

        return response()->json(status: 204);
    }
}
