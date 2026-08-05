<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PackageSearchController extends Controller
{
    use ScopesToAdministeredOrgs;

    /**
     * @return Collection<int, array{id: string, name: string, type: PackageType}>
     */
    public function __invoke(Request $request): Collection
    {
        $q = (string) $request->query('q', '');

        // Only packages reachable in the active scope — a customer-org admin must never
        // be able to attach another organization's package to their registry.
        return $this->scopePackageQuery(Package::query())
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')->limit(8)
            ->get(['id', 'name', 'type'])
            ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type]);
    }
}
