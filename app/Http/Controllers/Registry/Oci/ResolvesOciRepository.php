<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
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
     * The repository must already exist. kontorfix does not create one on push, for the same
     * reason NpmController and PypiController refuse an unknown package: a publish token must
     * not be able to invent names in a registry. The operator registers the repository first.
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

        $package = $group->packages()
            ->where('packages.type', PackageType::Docker)
            ->where('packages.name', $name)
            ->first();

        if ($package === null) {
            throw OciException::nameUnknown($name);
        }

        return $package;
    }
}
