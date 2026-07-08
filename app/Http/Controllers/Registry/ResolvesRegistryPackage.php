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
