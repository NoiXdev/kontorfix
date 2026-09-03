<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Registry\RegistryUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function __invoke(Request $request, RegistryUrl $url): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['packages' => [], 'registries' => [], 'customers' => []]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        // Customer (organization) management is super-admin only — hence search only
        // returns customer hits to super-admins, so nobody gets dead-end clicks.
        $isSuper = (bool) $request->user()?->isSuperAdmin();

        return response()->json([
            'packages' => $this->scopePackageQuery(Package::query())->where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'type'])->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value]),
            // `organization_id` and the eager-loaded organization slug are what RegistryUrl
            // needs to state the address: without the foreign key in the select list the
            // relation resolves to null and the URL would silently come out as /r//{slug}.
            'registries' => $this->scopeGroupQuery(Group::query())->where('name', 'ilike', $like)
                ->with('organization:id,slug')->orderBy('name')->limit(5)
                ->get(['id', 'name', 'slug', 'organization_id'])
                ->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'url_path' => $url->path($g)]),
            'customers' => $isSuper
                ? Organization::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                    ->get(['id', 'name', 'is_operator'])->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name, 'is_operator' => $o->is_operator])
                : [],
        ]);
    }
}
