<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\RegistryAccessService;
use Illuminate\Http\Request;

trait ResolvesRegistryPackage
{
    abstract protected function access(): RegistryAccessService;

    protected function authorizeGroup(Request $request, Group $group): void
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        if (! $this->access()->canAccessGroup($token, $group)) {
            abort($token ? 404 : 401, 'Authentication required for this registry.');
        }
    }

    protected function registryGroup(Request $request): Group
    {
        /** @var Group $group */
        $group = $request->attributes->get('registryGroup');

        return $group;
    }

    /**
     * Absolute base for the URLs handed to package clients (Composer `dist.url`, npm
     * `dist.tarball`, the PyPI index hrefs).
     *
     * Domain mode may use the request's host: ResolveRegistryContext has already matched
     * it against the `domains` table and 404s anything else, so it is a host this
     * instance answers to by definition. Slug mode may not — nothing there constrains
     * the `Host` header, so an injected one would end up as the download URL in every
     * client's lock file. It uses the configured application URL instead, which is the
     * same value every other generated link is rooted at (URL::forceRootUrl in
     * AppServiceProvider).
     */
    protected function registryBaseUrl(Request $request, Group $group): string
    {
        if ($request->attributes->get('registryDomainMode') === true) {
            return $request->getSchemeAndHttpHost();
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        return ($appUrl !== '' ? $appUrl : $request->getSchemeAndHttpHost()).'/r/'.$group->slug;
    }

    /**
     * Path prefix for metadata URLs (e.g. metadata-url in packages.json): empty
     * for a custom domain (registry sits at the host root), otherwise /r/{slug}.
     */
    protected function registryPathPrefix(Request $request, Group $group): string
    {
        return $request->attributes->get('registryDomainMode') === true ? '' : "/r/{$group->slug}";
    }

    protected function findAccessible(Request $request, Group $group, PackageType $type, string $fullName): Package
    {
        $package = $this->findLocal($request, $group, $type, $fullName);
        if ($package === null) {
            abort(404); // deliberately not 403 — don't leak existence
        }

        return $package;
    }

    /**
     * Like findAccessible(), but doesn't abort — for callers that want to still
     * try an upstream fallback locally on a miss (Composer fallthrough).
     */
    protected function findLocal(Request $request, Group $group, PackageType $type, string $fullName): ?Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        $package = Package::where('type', $type)->where('name', $fullName)->first();
        if (! $package || ! $this->access()->canAccessPackage($token, $group, $package)) {
            return null;
        }

        return $package;
    }

    /**
     * Whether a package with this name exists locally at all (regardless of access/group).
     * Prevents a privately hosted name from leaking to packagist/npmjs during
     * the upstream fallthrough (dependency confusion protection).
     */
    protected function packageExistsLocally(PackageType $type, string $fullName): bool
    {
        return Package::where('type', $type)->where('name', $fullName)->exists();
    }
}
