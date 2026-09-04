<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * The portal's landing page: the packages the addressed organization can install.
     *
     * A stub for now — the address, the switch and the gate around it are what this
     * change builds, and the list itself arrives with the portal package set.
     */
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('portalOrganization');

        return Inertia::render('portal/Packages', [
            // The addressed organization, not the viewer's own: an operator looking at a
            // customer's portal must navigate inside that customer's portal.
            'orgSlug' => $organization->slug,
            'packages' => [],
        ]);
    }

    /**
     * GET /portal — the address the portal used to live at, and the one every surface that
     * has no organization in hand (the sidebar, the dashboard) points to. It answers the
     * question "which portal is this viewer's own?" once, so nothing else has to.
     */
    public function home(Request $request): Response|RedirectResponse
    {
        $organization = $request->user()->organization;

        // `users.organization_id` is nullable and RegisteredUserController::store() creates
        // a self-registered account without one, so "no home organization" is a state the
        // application really produces — reading ->slug off it would be a 500 on the first
        // page such an account is sent to. It gets a page saying an operator still has to
        // assign it, which is what the user-centric portal effectively gave it before: a
        // 200 with nothing in it.
        if ($organization === null) {
            return Inertia::render('portal/NoOrganization');
        }

        return redirect()->route('portal.packages.index', $organization->slug);
    }
}
