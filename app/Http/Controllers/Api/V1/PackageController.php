<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Resources\Api\PackageResource;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PackageController extends Controller
{
    use ClampsPageSize, ScopesApiToUser;

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $packages = $this->scopePackageRead(Package::query())
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
            ->latest()
            ->paginate($this->perPage($request))
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

        // A git credential is an organization-owned secret: referencing a foreign one
        // would make the sync send that organization's decrypted token to the submitted
        // repository host. Mirrors the check on the admin surface.
        if ($request->filled('git_credential_id')) {
            $credential = GitCredential::findOrFail($request->validated('git_credential_id'));
            $this->assertCanWriteOrg($credential->organization_id);

            // …and it is bound to one host, so it may not be paired with a repository
            // anywhere else (see GitCredential::permits).
            if (! $credential->permits($request->validated('repository_url'))) {
                throw ValidationException::withMessages([
                    'repository_url' => $credential->hostMismatchMessage(),
                ]);
            }
        }

        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($groupIds);

        // Publish-based packages (npm, Python) without a repository have nothing to sync
        // from git — they are filled by pushing artifacts.
        if ($package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

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
