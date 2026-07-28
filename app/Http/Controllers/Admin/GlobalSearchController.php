<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['packages' => [], 'registries' => [], 'customers' => []]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        // Customer management is admin-only (the detail page sits behind role:admin) — hence
        // search only returns customer hits to admins, so maintainers don't get dead-end clicks.
        $isAdmin = $request->user()?->role === UserRole::Admin;

        return response()->json([
            'packages' => Package::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'type'])->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value]),
            'registries' => Group::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'slug'])->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug]),
            'customers' => $isAdmin
                ? Organization::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                    ->get(['id', 'name', 'is_operator'])->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name, 'is_operator' => $o->is_operator])
                : [],
        ]);
    }
}
