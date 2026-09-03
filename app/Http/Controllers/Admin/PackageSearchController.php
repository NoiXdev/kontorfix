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
     * The pool the assignment picker offers.
     *
     * @return Collection<int, array{id: string, name: string, type: PackageType, shared: bool}>
     */
    public function __invoke(Request $request): Collection
    {
        $q = (string) $request->query('q', '');

        // Only packages that may actually be assigned in the active scope — a customer-org
        // admin must never be able to attach another organization's package to their
        // registry, and must be offered the operator's shared ones.
        return $this->scopeAssignablePackageQuery(Package::query())
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')->limit(8)
            ->get(['id', 'name', 'type', 'shared'])
            // `shared` travels with every row: the picker distinguishes the two visually so
            // the operator can see they are handing over a package other tenants receive
            // too. A column-restricted get() that omitted it would yield null rather than
            // fail, and the marker would silently never appear.
            ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'shared' => $p->shared]);
    }
}
