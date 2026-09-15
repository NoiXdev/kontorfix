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
                    'trusts_email_claim' => $p->trusts_email_claim,
                    'has_secret' => $p->hasSecret(),
                    'default_role' => $p->default_role,
                    'default_organization_id' => $p->default_organization_id,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/oidc/Create', [
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreOidcProviderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['enabled'] = $request->boolean('enabled');
        $data['allow_registration'] = $request->boolean('allow_registration');
        $data['trusts_email_claim'] = $request->boolean('trusts_email_claim');

        OidcProvider::create($data);

        // Explicitly to the index, not back(): the form now lives on its own
        // `admin/oidc/create` page, and back() would return there — to a freshly emptied
        // form that renders no `flash.success`. The index shows the new row and the flash.
        return redirect()->route('admin.oidc.index')->with('success', 'OIDC-Provider erstellt.');
    }

    public function destroy(OidcProvider $provider): RedirectResponse
    {
        $provider->delete();

        return back()->with('success', 'OIDC-Provider gelöscht.');
    }

    /**
     * Single-purpose toggle for `trusts_email_claim` (see OidcUserResolver): the only way,
     * short of hand-written SQL, to turn the flag off on a provider that predates it — the
     * migration backfilled every existing row to `true`. Deliberately narrow: a full
     * provider-edit endpoint would also need to cover client-secret rotation and endpoint
     * re-discovery, which is out of scope here.
     */
    public function trust(Request $request, OidcProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'trusts_email_claim' => ['required', 'boolean'],
        ]);

        $provider->update(['trusts_email_claim' => $validated['trusts_email_claim']]);

        return back()->with('success', $provider->trusts_email_claim
            ? 'Provider als vertrauenswürdig für E-Mail-Zusicherungen markiert.'
            : 'Provider als nicht vertrauenswürdig für E-Mail-Zusicherungen markiert.');
    }

    /**
     * Rotates the client secret in place.
     *
     * Single-purpose, like {@see trust()} beside it, and for the same reason: the resource
     * route exposes no update action, so the only way to change a leaked secret used to be
     * delete-and-recreate — which cascades away every OidcIdentity linked to the provider.
     * Rotating therefore meant unlinking every SSO user, which is a penalty applied to
     * exactly the secret most in need of rotating.
     *
     * Only the secret. Re-discovering endpoints or renaming a provider are different acts
     * with different risks, and a combined edit form would let one of them ride along
     * unnoticed with the other.
     */
    public function rotateSecret(Request $request, OidcProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            // `required` rather than `nullable`: an empty value would leave the provider
            // present but unable to authenticate, which looks like a working configuration
            // and fails only at the next login.
            'client_secret' => ['required', 'string', 'max:500'],
        ]);

        $provider->update(['client_secret' => $validated['client_secret']]);

        return back()->with('success', 'Client-Secret rotiert. Bestehende SSO-Verknüpfungen bleiben erhalten.');
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
