<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ClampsPageSize;
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
    use ClampsPageSize;

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::orderBy('name')->paginate($this->perPage($request))
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
        // Packages too, not only users and registries — the same four conditions the console
        // copy checks (Admin\OrganizationController::destroy()). Deleting the organization's
        // last registry cascades the pivot rows but leaves its packages alive and owned, so
        // an organization with 0 users, 0 registries and N packages used to pass every check
        // here and reach delete(), where the restrictOnDelete foreign key raised a bare
        // SQLSTATE[23503] — a 500 with a stack trace on the API where the console returned a
        // readable message. Both halves of the rule now say the same thing.
        if ($organization->is_operator || $organization->users()->exists()
            || $organization->groups()->exists() || $organization->packages()->exists()) {
            throw ValidationException::withMessages([
                'organization' => 'Organisation ist Betreiber oder nicht leer (erst Pakete/Registries/Nutzer entfernen).',
            ]);
        }

        $organization->delete();

        return response()->json(status: 204);
    }
}
