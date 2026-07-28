<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Resources\Api\PackageResource;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $packages = Package::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100))
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function show(Package $package): PackageResource
    {
        return new PackageResource($package->load('versions'));
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($request->validated('group_ids', []));
        SyncPackage::dispatch($package);

        // sync_status kommt aus einem DB-Default (Migration), der von Eloquent nach
        // einem plain INSERT nicht automatisch ins Model geladen wird — ohne refresh()
        // wäre die Property beim Serialisieren null und PackageResource würde crashen.
        $package->refresh();

        return (new PackageResource($package))->response()->setStatusCode(201);
    }

    public function resync(Package $package): PackageResource
    {
        SyncPackage::dispatch($package);

        return new PackageResource($package);
    }

    public function destroy(Package $package): JsonResponse
    {
        $package->delete();

        return response()->json(status: 204);
    }
}
