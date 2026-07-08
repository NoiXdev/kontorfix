<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PackageSearchController extends Controller
{
    /**
     * @return Collection<int, array{id: string, name: string, type: PackageType}>
     */
    public function __invoke(Request $request): Collection
    {
        // TODO(multi-tenant): den globalen Pool auf die Organisation des Users einschränken.
        $q = (string) $request->query('q', '');

        return Package::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')->limit(8)
            ->get(['id', 'name', 'type'])
            ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type]);
    }
}
