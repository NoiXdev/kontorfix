<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOidcProviderRequest;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Services\Auth\Oidc\OidcDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OidcProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/oidc/Index', [
            'providers' => OidcProvider::latest()->get()
                ->map(fn (OidcProvider $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'issuer' => $p->issuer,
                    'enabled' => $p->enabled,
                    'allow_registration' => $p->allow_registration,
                    'has_secret' => $p->hasSecret(),
                    'default_role' => $p->default_role,
                    'default_organization_id' => $p->default_organization_id,
                ]),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreOidcProviderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['enabled'] = $request->boolean('enabled');
        $data['allow_registration'] = $request->boolean('allow_registration');

        OidcProvider::create($data);

        return back()->with('success', 'OIDC-Provider erstellt.');
    }

    public function destroy(OidcProvider $provider): RedirectResponse
    {
        $provider->delete();

        return back()->with('success', 'OIDC-Provider gelöscht.');
    }

    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'issuer' => ['required', 'url'],
        ]);

        try {
            $endpoints = app(OidcDiscovery::class)->discover($validated['issuer']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($endpoints);
    }
}
