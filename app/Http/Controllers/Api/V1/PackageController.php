<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Resources\Api\PackageResource;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    use ScopesApiToUser;

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $packages = $this->scopePackageRead(Package::query())
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100))
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function show(Package $package): PackageResource
    {
        $this->assertCanReadPackage($package);

        return new PackageResource($package->load('versions'));
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        // A package may only be attached to registries the caller administers.
        $groupIds = $request->validated('group_ids', []);
        foreach ($groupIds as $groupId) {
            $this->assertCanWriteGroup(Group::findOrFail($groupId));
        }

        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($groupIds);
        SyncPackage::dispatch($package);

        // sync_status comes from a DB default (migration) that Eloquent doesn't
        // automatically load into the model after a plain INSERT — without refresh()
        // the property would be null when serializing and PackageResource would crash.
        $package->refresh();

        return (new PackageResource($package))->response()->setStatusCode(201);
    }

    public function resync(Package $package): PackageResource
    {
        $this->assertCanWritePackage($package);

        SyncPackage::dispatch($package);

        return new PackageResource($package);
    }

    public function destroy(Package $package): JsonResponse
    {
        $this->assertCanWritePackage($package);

        $package->delete();

        return response()->json(status: 204);
    }
}
