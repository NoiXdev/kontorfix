<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageAbandonmentRequest;
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

        // Mirrors Admin\PackageController::store: the request, not the raw submitted
        // value, is authoritative for the stored mode — otherwise a type that didn't
        // submit source_mode at all (or submitted one it doesn't allow) would fall through
        // to the column's DB default ('publish') instead of the type's real default.
        $attributes = $request->safe()->except('group_ids');
        $attributes['source_mode'] = $request->effectiveSourceMode(PackageType::from($request->validated('type')))->value;
        // The owner is the organization the selected registries belong to; the request has
        // already refused a selection spanning more than one. Mirrors Admin\PackageController::store.
        $attributes['organization_id'] = $request->ownerOrganizationId();

        $package = Package::create($attributes);
        $package->groups()->sync($groupIds);

        // Publish-based packages (npm, Python) are filled by pushing artifacts; a
        // repository_url on one is reference-only (npm publish uploads a tarball, not the
        // tree), so it must not queue a sync. Mirrors Admin\PackageController::store.
        if ($package->isGitSourced() && $package->repository_url !== null) {
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

        // Unlike store()/update(), where dispatching is a side effect of a save that
        // already succeeded, dispatch *is* the entire point of this endpoint — silently
        // declining it while still returning 200 would tell the caller a resync happened
        // when nothing was queued. A publish-based package (npm, Python) is filled by
        // pushing artifacts, not synced from a repository, so reject synchronously instead,
        // matching the 409 convention NpmController/PypiController already use for "this
        // package's mode forbids this operation".
        abort_if(! $package->isGitSourced(), 409, 'Dieses Paket ist nicht git-basiert und kann nicht synchronisiert werden.');

        SyncPackage::dispatch($package);

        return new PackageResource($package);
    }

    /**
     * Marks or unmarks a package as abandoned. Mirrors Admin\PackageController::abandonment
     * (same request, same "don't reset abandoned_at on a re-mark" rule) — kept as its own
     * action here too, alongside resync()/destroy(), rather than folded into a general
     * update() this controller does not have.
     */
    public function abandonment(UpdatePackageAbandonmentRequest $request, Package $package): PackageResource
    {
        $this->assertCanWritePackage($package);

        $abandoned = $request->boolean('abandoned');

        $package->update([
            'abandoned_at' => $abandoned ? ($package->abandoned_at ?? now()) : null,
            'replacement_package' => $abandoned ? $request->validated('replacement_package') : null,
            'abandonment_reason' => $abandoned ? $request->validated('abandonment_reason') : null,
        ]);

        return new PackageResource($package);
    }

    public function destroy(Package $package): JsonResponse
    {
        $this->assertCanWritePackage($package);

        $package->delete();

        return response()->json(status: 204);
    }
}
