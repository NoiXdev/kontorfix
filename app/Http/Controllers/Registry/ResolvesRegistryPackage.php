<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Http\AppUrl;
use App\Services\RegistryAccessService;
use App\Services\Upstream\UpstreamCache;
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
     * same value every other generated link is rooted at (App\Http\Middleware\PinUrlRoot)
     * and is read through AppUrl, so a value written without a scheme still produces an
     * absolute download URL rather than a scheme-less one.
     */
    protected function registryBaseUrl(Request $request, Group $group): string
    {
        if ($request->attributes->get('registryDomainMode') === true) {
            return $request->getSchemeAndHttpHost();
        }

        return (AppUrl::root() ?? $request->getSchemeAndHttpHost()).'/r/'.$group->slug;
    }

    /**
     * Path prefix for metadata URLs (e.g. metadata-url in packages.json): empty
     * for a custom domain (registry sits at the host root), otherwise /r/{slug}.
     */
    protected function registryPathPrefix(Request $request, Group $group): string
    {
        return $request->attributes->get('registryDomainMode') === true ? '' : "/r/{$group->slug}";
    }

    /**
     * Refuses a package-name segment that is a relative path component.
     *
     * The Composer `p2` and npm packument constraints (`[a-z0-9_.-]+`, `[a-z0-9._~-]+`,
     * `[a-z0-9._-]+`) all admit `.` and `..`. Neither can name a local package, so such a
     * request falls through to the upstream — where the segment is interpolated into the
     * outbound path, and where the answer is then written to `upstream_metadata_cache`
     * under that name. The registry routes carry no throttle by design, so those rows are
     * unbounded, and a caller that can choose `..` is choosing which upstream path is
     * fetched rather than which package.
     *
     * Reuses the refusal set the artifact cache key already uses. Its guard sits in
     * ProxyDownloadController and never covered these two paths, because the sinks here
     * are a URL path and a database key rather than a Flysystem key — but the values that
     * must not be admitted are exactly the same, and refusing them outright rather than
     * normalising them keeps the decision from being undone by a later pass.
     */
    protected function assertProxyableName(string ...$segments): void
    {
        foreach ($segments as $segment) {
            abort_unless(UpstreamCache::isSafeKeySegment($segment), 404);
        }
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
