<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ClampsPageSize;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Http\Resources\Api\GroupResource;
use App\Models\Group;
use App\Services\Package\SharedAssignment;
use App\Services\Slugs\SlugClaimGuard;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiGroup('Registries')]
class GroupController extends Controller
{
    use ClampsPageSize, ScopesApiToUser;

    /** Eigene Registries auflisten. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(
            $this->scopeGroupRead(Group::query())->orderBy('name')->paginate($this->perPage($request))
        );
    }

    /** Registry-Details abrufen. */
    public function show(Group $group): GroupResource
    {
        $this->assertCanReadGroup($group);

        return new GroupResource($group);
    }

    /**
     * Neue Registry anlegen.
     *
     * Nur für Organisations-Admins/-Maintainer. Kann optional bereits Pakete zuweisen
     * (`package_ids`), sofern die Organisation diese verwalten darf.
     */
    public function store(StoreGroupRequest $request, SharedAssignment $sharedAssignment, SlugClaimGuard $slugs): JsonResponse
    {
        $organizationId = $this->resolveWriteOrg($request->validated('organization_id'));

        // Never let a registry be seeded with another organization's packages.
        $packageIds = $request->validated('package_ids', []);
        $this->assertCanAttachPackages($packageIds, $organizationId);

        // …and never with a shared package the caller may not hand out. The registry does not
        // exist yet, so it carries nothing and every shared package in the submission is one
        // this write ADDS. Mirrors Admin\GroupController::store().
        $this->assertSharedAssignmentsUnchanged([], $packageIds);

        // …and never leaving the registry serving a shared package beside an own one of
        // the same name. The registry starts empty, so its post-state is the submission.
        // Before the insert, so a refusal leaves no empty registry behind.
        $sharedAssignment->assertReplacementAssignable($packageIds);

        // StoreGroupRequest's UnclaimedSlug rule already checked this — this is the
        // authoritative, race-proof re-check immediately before the write, same as
        // Admin\GroupController::store(). See App\Services\Slugs\SlugClaimGuard's docblock.
        $group = $slugs->claimRegistrySlug(
            (string) $request->validated('slug'),
            function () use ($request, $organizationId, $packageIds) {
                $group = Group::create([
                    'name' => $request->validated('name'),
                    'slug' => $request->validated('slug'),
                    'public' => $request->boolean('public'),
                    'organization_id' => $organizationId,
                ]);
                $group->packages()->sync($packageIds);

                return $group;
            },
            organizationId: $organizationId,
        );

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    /** Registry aktualisieren. */
    public function update(UpdateGroupRequest $request, Group $group, SlugClaimGuard $slugs): GroupResource
    {
        $this->assertCanWriteGroup($group);

        $attributes = [
            'name' => $request->validated('name'),
            'public' => $request->boolean('public'),
        ];

        // The slug only when the caller actually sent one — a PUT that names just the fields
        // it wants changed keeps leaving the registry's address alone. Validating it here and
        // then dropping it would leave UnclaimedSlug enforced on the console only, which is
        // precisely the half-enforced invariant it exists to close — and the same reasoning
        // is why the write below goes through the authoritative guard, not just the rule.
        if ($request->filled('slug')) {
            $slugs->claimRegistrySlug(
                (string) $request->validated('slug'),
                fn () => $group->update([...$attributes, 'slug' => $request->validated('slug')]),
                organizationId: $group->organization_id,
                excludeGroupId: $group->id,
            );
        } else {
            $group->update($attributes);
        }

        return new GroupResource($group);
    }

    /** Registry löschen. */
    public function destroy(Group $group): JsonResponse
    {
        $this->assertCanWriteGroup($group);

        $group->delete();

        return response()->json(status: 204);
    }
}
