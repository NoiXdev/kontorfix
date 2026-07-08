<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/packages/Index', [
            'packages' => Package::withCount('groups')->latest()->paginate(25)->through(fn (Package $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'sync_status' => $p->sync_status,
                'sync_error' => $p->sync_error,
                'groups_count' => $p->groups_count,
                'synced_at' => $p->synced_at?->diffForHumans(),
            ]),
            'groups' => Group::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(StorePackageRequest $request): RedirectResponse|JsonResponse
    {
        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($request->validated('group_ids', []));
        SyncPackage::dispatch($package);

        // Der PackagePicker legt Pakete inline per fetch an und braucht das
        // erstellte Paket zurück, um es direkt der Auswahl hinzuzufügen.
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $package->id,
                'name' => $package->name,
                'type' => $package->type,
            ], 201);
        }

        return back()->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
    }

    public function destroy(Package $package): RedirectResponse
    {
        $package->delete();

        return back()->with('success', 'Paket gelöscht.');
    }
}
