<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Registry\OciSettings;
use App\Services\RegistryAccessService;
use Illuminate\Http\Request;

trait ResolvesOciRepository
{
    abstract protected function access(): RegistryAccessService;

    protected function ociGroup(Request $request): Group
    {
        /** @var Group $group */
        $group = $request->attributes->get('registryGroup');

        return $group;
    }

    /**
     * The repository name as the CLIENT addressed it: the bare name on a registry domain,
     * and `<org>/<registry>/` plus the bare name when the registry was addressed by path
     * namespace (ResolveOciContext).
     *
     * Every URL this registry hands back — an upload session's `Location`, a finished
     * blob's, a stored manifest's — has to be expressed in the caller's own address space.
     * `{name}` reaches a controller as the BARE name, deliberately, because that is what
     * every lookup is against; a `Location` built from it points at
     * `/v2/meinapp/blobs/uploads/<id>`, which on the instance host names no organization
     * and no registry and is therefore a 404. A client follows an upload Location without
     * asking, so the push simply dies there: `unexpected status from PUT request … 404 Not
     * Found`, which is exactly how this was found — a real `docker push` in bin/e2e, not a
     * reading of the controllers.
     */
    protected function ociAddressedName(Request $request, string $name): string
    {
        return ((string) $request->attributes->get('registryOciNamePrefix')).$name;
    }

    /**
     * The inverse, for a repository name the CLIENT supplied in a request rather than in
     * the path — today only the cross-repository mount's `from=`, which a client writes in
     * the same address space it writes an image reference in.
     *
     * Null when the value names something outside the address this request came in on (a
     * different registry's namespace, or no namespace at all in path mode). The caller
     * treats that exactly as it treats an unknown source repository, which the OCI spec
     * already defines as a fall-through to a normal upload rather than an error — so this
     * cannot be used to probe which namespaces exist.
     */
    protected function ociBareName(Request $request, string $addressed): ?string
    {
        $prefix = (string) $request->attributes->get('registryOciNamePrefix');

        if ($prefix === '') {
            return $addressed;
        }

        return str_starts_with($addressed, $prefix) ? substr($addressed, strlen($prefix)) : null;
    }

    /**
     * The read path. An anonymous caller gets 401 so the client knows to authenticate —
     * UNLESS the group is public, in which case `RegistryAccessService::canAccessGroup()`
     * short-circuits true for a null token and the anonymous caller proceeds to package
     * resolution, exactly as it does for npm/composer/pypi. A caller with a token that
     * simply may not see this registry gets the same NAME_UNKNOWN an absent repository
     * gets, so a token cannot enumerate other tenants' image names by response code alone.
     */
    protected function ociRepository(Request $request, Group $group, string $name): Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if (! $this->access()->canAccessGroup($token, $group)) {
            throw $token === null ? OciException::unauthorized() : OciException::nameUnknown($name);
        }

        $package = $this->access()->packagesFor($group)
            ->first(fn (Package $p): bool => $p->type === PackageType::Docker && $p->name === $name);

        if ($package === null) {
            throw OciException::nameUnknown($name);
        }

        return $package;
    }

    /**
     * The write path. Strict and org-bound, with NO public-registry shortcut — a publicly
     * readable registry is not publicly writable, the same rule NpmController::respondPublish
     * states for npm.
     *
     * The repository must already exist, unless an operator has switched push-time creation
     * on (`oci_auto_create_repositories`, off by default). Refusing an unknown name is the
     * same rule NpmController and PypiController enforce — a publish token must not invent
     * names in a registry — but Harbor creates the repository on first push, so the setting
     * exists for an instance that wants that habit. Even when it is on, a name held by
     * ANOTHER organization stays NAME_UNKNOWN: the alternative is a publish token
     * discovering foreign repository names by response code.
     *
     * Resolved strictly within the addressed registry's OWN organization — never through
     * $group->packages(), which carries every package assigned to the group regardless of
     * who owns it, shared ones included, and applies no expiry predicate at all. Sharing
     * hands out reads, never writes (docs/development.md, "Shared packages"; the identical
     * rule NpmController::respondPublish() and PypiController::upload() already enforce): a
     * customer's publish token must not be answered for the operator's shared repository —
     * push, overwrite, or delete an image inside the operator's own organization — nor for
     * an assignment whose `available_until` has lapsed. This was a Critical: unfixed, a
     * publish token for any group a shared Docker repository is assigned to got 201 on
     * `POST .../blobs/uploads/`, 201 on `PUT .../manifests/<ref>` and 202 on
     * `DELETE .../manifests/<digest>` inside the operator's namespace — every other
     * customer assigned that repository then pulls whatever was pushed. A shared name (or
     * an expired one) is now answered exactly like an unknown one, matching
     * NpmController::respondPublish()'s own "own-organization only" resolution and
     * RegistryAccessService::packageBelongsToGroup()'s expiry check.
     */
    protected function ociWritableRepository(Request $request, Group $group, string $name): Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if ($token === null) {
            throw OciException::unauthorized();
        }

        if (! $this->access()->canPublishToGroup($token, $group)) {
            throw OciException::denied();
        }

        $package = Package::where('type', PackageType::Docker)
            ->where('name', $name)
            ->where('organization_id', $group->organization_id)
            ->first();

        if ($package === null) {
            return $this->ociCreateRepository($group, $name);
        }

        if (! $this->access()->packageBelongsToGroup($group, $package)) {
            throw OciException::nameUnknown($name);
        }

        return $package;
    }

    /**
     * Push-time creation of a repository nobody registered, behind the operator's setting.
     *
     * Off — the default — refuses with NAME_UNKNOWN, the protocol code a client expects,
     * but with a message that names the setting: an operator who has just pointed a Harbor
     * habit at kontorfix otherwise goes to the logs for a decision that is one checkbox away.
     *
     * A name already held by another organization is refused too, and the setting is
     * consulted FIRST so that a switched-off instance answers every unresolvable name with
     * one identical message: were the foreign-name refusal worded differently, a publish
     * token could tell "somebody else has this name" from "nobody has it" by reading the
     * body, which is a leak the old unconditional NAME_UNKNOWN did not have.
     */
    private function ociCreateRepository(Group $group, string $name): Package
    {
        if (! app(OciSettings::class)->autoCreateEnabledFor($group->organization)) {
            throw OciException::nameUnknownAutoCreateDisabled($name);
        }

        if (Package::where('type', PackageType::Docker)->where('name', $name)->exists()) {
            throw OciException::nameUnknown($name);
        }

        $package = Package::create([
            'organization_id' => $group->organization_id,
            'type' => PackageType::Docker,
            // Stated rather than left to the column default. A Docker repository receives
            // its versions by being pushed to; a row that ends up git-sourced is never
            // synced and says so only by staying empty.
            'source_mode' => PackageSourceMode::Publish,
            'name' => $name,
        ]);

        $group->packages()->attach($package);

        return $package;
    }
}
