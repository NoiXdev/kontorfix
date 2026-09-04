<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Portal\PortalPackages;
use App\Services\Portal\PortalRegistryAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function __construct(private readonly PortalPackages $packages) {}

    /**
     * The portal's landing page: the packages the addressed organization can install.
     *
     * The set itself — which packages, in what order, from which registries, and whether each
     * assignment is still in force — is PortalPackages' answer. Nothing is decided here; this
     * only turns its rows into the payload the page renders.
     *
     * `in_force` travels TWICE, and that is the point. The row's flag says whether the package
     * is usable at all (in force in at least one registry); each entry of `registries` says
     * whether THAT registry still serves it. They differ exactly in the case that matters, a
     * package live in one of a customer's registries and lapsed in another — and there the row
     * carries no lapsed badge, correctly, while the link to the lapsed registry must still be
     * marked where the customer would click it.
     *
     * The date comes off the ENTRY, never off `$row['package']->pivot`: the row keeps the
     * Package instance of whichever registry was seen first, so its pivot is in a mixed row
     * silently another registry's assignment. PortalPackages unsets the relation for that
     * reason, so the mistake is now a null rather than a plausible wrong day — but the entry is
     * still the only place to ask.
     */
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('portalOrganization');

        return Inertia::render('portal/Packages', [
            // The addressed organization, not the viewer's own: an operator looking at a
            // customer's portal must navigate inside that customer's portal.
            'orgSlug' => $organization->slug,
            'packages' => $this->packages->for($organization)->map(fn (array $row): array => [
                'id' => $row['package']->id,
                'name' => $row['package']->name,
                'type' => $row['package']->type->value,
                'description' => $row['package']->description,
                // Owned by the operator organization and shared into this customer's
                // registries — the `geteilt` badge.
                'shared' => $row['package']->shared,
                'in_force' => $row['in_force'],
                // Only registries the portal shows: PortalPackages filters on
                // `groups.portal_enabled`, so no link rendered from this list can reach a
                // registry GroupPolicy::view() would answer 403 for.
                'registries' => $row['groups']->map(fn (PortalRegistryAssignment $entry): array => [
                    'id' => $entry->group->id,
                    'name' => $entry->group->name,
                    'slug' => $entry->group->slug,
                    'in_force' => $entry->in_force,
                    'available_until' => $entry->available_until?->toDateString(),
                    // ->all(), so the nested value is a plain list and not a Collection:
                    // Collection's TValue is INVARIANT, and a nullable inside a nested one
                    // makes this shape unprovable against a declaration identical to itself
                    // (the same measurement PortalRegistryAssignment was extracted for). The
                    // JSON is the same either way.
                ])->values()->all(),
            ])->values(),
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
