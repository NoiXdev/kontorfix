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

    protected function registryBaseUrl(Request $request, Group $group): string
    {
        if ($request->attributes->get('registryDomainMode') === true) {
            return $request->getSchemeAndHttpHost();
        }

        return $request->getSchemeAndHttpHost().'/r/'.$group->slug;
    }

    /**
     * Pfad-Präfix für Metadaten-URLs (z.B. metadata-url in packages.json): bei
     * Custom-Domain leer (Registry liegt an der Host-Wurzel), sonst /r/{slug}.
     */
    protected function registryPathPrefix(Request $request, Group $group): string
    {
        return $request->attributes->get('registryDomainMode') === true ? '' : "/r/{$group->slug}";
    }

    protected function findAccessible(Request $request, Group $group, PackageType $type, string $fullName): Package
    {
        $package = $this->findLocal($request, $group, $type, $fullName);
        if ($package === null) {
            abort(404); // bewusst kein 403 — Existenz nicht leaken
        }

        return $package;
    }

    /**
     * Wie findAccessible(), bricht aber nicht ab — für Aufrufer, die bei einem Miss
     * lokal noch einen Upstream-Fallback versuchen wollen (Composer-Fallthrough).
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
     * Ob ein Paket dieses Namens überhaupt lokal existiert (unabhängig von Zugriff/Gruppe).
     * Verhindert, dass ein privat gehosteter Name beim Upstream-Fallthrough zu packagist/npmjs
     * durchsickert (Dependency-Confusion-Schutz).
     */
    protected function packageExistsLocally(PackageType $type, string $fullName): bool
    {
        return Package::where('type', $type)->where('name', $fullName)->exists();
    }
}
