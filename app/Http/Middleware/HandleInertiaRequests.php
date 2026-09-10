<?php

namespace App\Http\Middleware;

use App\Enums\NotificationEvent;
use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Portal\PortalContext;
use App\Services\Scope\OrgScope;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('app.version'),
            // Single source of truth for registry-type metadata (labels, publish-based).
            'registryTypeMeta' => PackageType::metadata(),
            // Single source of truth for the failure-digest event checkboxes, exactly as
            // registryTypeMeta above.
            'notificationEventMeta' => NotificationEvent::metadata(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            // Lets the login page hide the "sign up" link when self-registration is off.
            'registrationEnabled' => fn (): bool => SystemSetting::current()->registration_enabled,
            'auth' => [
                'user' => $user,
                // Drives the navigation: which surfaces to show. `console` = any org
                // admin/maintainer (scoped registry surface); `super` = the global
                // super-admin (instance-wide administration).
                'can' => [
                    'console' => $user instanceof User && $user->canAdministerConsole(),
                    'super' => $user instanceof User && $user->isSuperAdmin(),
                ],
            ],
            // The sidebar organization scope switch. Null when not applicable (logged out
            // or a single-org admin with nothing to switch between).
            'scope' => fn () => $user instanceof User ? app(OrgScope::class)->share() : null,
            // The customer portal's header: which organization the URL addresses, where the
            // viewer may switch to, and the two flags the header and the token form read.
            // Null on every request that addresses no portal, which is most of them — the
            // header is a shared prop, so its absence is what keeps it off the console.
            'portal' => fn () => $this->portal($request, $user),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'plainTextToken' => fn () => $request->session()->get('plainTextToken'),
                'plainApiKey' => fn () => $request->session()->get('plainApiKey'),
                'incomingWebhookSecret' => fn () => $request->session()->get('incomingWebhookSecret'),
                'incomingWebhookUrl' => fn () => $request->session()->get('incomingWebhookUrl'),
            ],
        ]);
    }

    /**
     * The portal header's props, or null on a request that addresses no portal.
     *
     * @return array{organization: array{name: string, slug: string}, areas: array{packages: string, registries: string, setup: string}, switchable: list<array{name: string, slug: string}>, viewing_as_operator: bool, may_mint_tokens: bool, may_publish_tokens: bool}|null
     */
    private function portal(Request $request, ?User $user): ?array
    {
        $organization = PortalContext::find($request);

        if ($organization === null || ! $user instanceof User) {
            return null;
        }

        // ONE STATEMENT of the membership question, because the two flags below are that
        // question and its inverse rather than two rules. `may_mint_tokens` has to be the
        // same answer TokenController::store() gives — it asks exactly this `in_array`
        // against exactly this list — or the form is offered to someone the controller then
        // refuses, which is the shown-and-then-refused shape this codebase replaced with
        // hiding on the admin registry page. `viewing_as_operator` is the same question
        // negated: a viewer standing in a portal they are not a member of is there on
        // ResolvePortalContext's operator branch and on no other. Written once so that a
        // later change to what "membership" means cannot be made to only one of them.
        //
        // NOT administersOperatorOrganization(): that is the OPERATOR question, and the two
        // are different. An operator-organization account looking at its OWN portal is a
        // member of it, mints there like anybody else, and must not be told it is looking at
        // somebody else's portal.
        $accessible = $user->accessibleOrganizationIds();
        // belongsToOrganization() IS `in_array($id, accessibleOrganizationIds(), true)`, and it
        // is what RegistryTokenPolicy::create() and GroupPolicy already ask. Spelling the
        // in_array out here made "these cannot drift apart" a claim resting on a comment;
        // calling the method makes it a property of the code.
        $isMember = $user->belongsToOrganization($organization->id);

        return [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            // THE PORTAL'S TWO AREAS, spec §3: the package list the customer lands on, and the
            // registries with the setup snippets and the token form behind them. Shared rather
            // than assembled in the header, and asserted server-side, because the registries area
            // spent this branch with no link into it at all: task 1 made it the landing page,
            // task 3 moved the landing page to the package list and repointed both sidebar
            // entries, and nothing then pointed anywhere at `portal.registries.index`. An
            // organization whose package list is still empty — a registry handed over before
            // anything is assigned to it, which is the moment the portal exists for — could not
            // reach the snippets that tell it how to configure Composer at all.
            //
            // In the SHARED prop and not in the landing page's own payload: the sidebar is the
            // only other portal-wide surface and it has no organization in hand (both its entries
            // point at `portal.home` for that reason), so the header is where a portal-wide
            // navigation can live. Every portal page mounts it, so the entry point does not
            // depend on what the landing page happens to be next.
            //
            // Paths, not slugs the header would assemble: `/c/` is declared once, as the prefix
            // in routes/web.php, and a second spelling of it in the browser is a form that can
            // drift from the address the application answers on while every test still passes —
            // the reason PortalUrl gives for deriving its own template from the route.
            'areas' => [
                'packages' => route('portal.packages.index', $organization->slug, absolute: false),
                'registries' => route('portal.registries.index', $organization->slug, absolute: false),
                // Task 7's organization-wide Einrichtung tab — a third area, not a fourth
                // per-group tab, since its snippet set (SetupSnippetBuilder::forOrganization())
                // addresses every registry the org-wide token can reach at once.
                'setup' => route('portal.setup', $organization->slug, absolute: false),
            ],
            // Built from the viewer's OWN memberships, never a broader set: feeding this the
            // customer directory would make that directory a by-product of navigation, and
            // an operator account's accessible set is its own organizations — not every
            // customer portal it is allowed to open.
            //
            // Filtered on `portal_enabled` because this is navigation and
            // ResolvePortalContext answers 404 for an organization whose portal is off: an
            // unfiltered entry is a link the viewer can see and cannot follow. The addressed
            // organization always survives the filter (the middleware has already required
            // it), so the switcher's current value is never one of the rows it dropped.
            'switchable' => Organization::whereIn('id', $accessible)
                ->where('portal_enabled', true)
                ->orderBy('name')->get(['name', 'slug'])
                ->map(fn (Organization $o): array => ['name' => $o->name, 'slug' => $o->slug])
                ->values()->all(),
            'viewing_as_operator' => ! $isMember,
            'may_mint_tokens' => $isMember,
            // Whether the "Veröffentlichen" ability may be offered — the CONJUNCTION of the
            // two clauses RegistryTokenPolicy::create() applies, in its order: membership
            // first, then administers() for the Publish ability specifically. `$isMember` is
            // reused rather than respelled, and administers() is the policy's own method, so
            // this is one statement of each clause and not a third copy of either.
            //
            // The membership half is not redundant. administers() answers Admin EVERYWHERE
            // for a super-admin, so without it this flag was true for an operator account
            // standing in a customer's portal — where `may_mint_tokens` is false and the two
            // flags therefore disagreed. That was harmless only for as long as the publish
            // flag stayed inside the hidden form, which is a guarantee about where a future
            // template puts it rather than one the code makes. Now it cannot disagree: a
            // viewer who may not mint at all may not mint a publish token either.
            //
            // The page used to gate this on `auth.can.console`, which means "administers
            // SOME organization". The policy means "administers THIS one", so an admin of A
            // who is a plain member of B was offered "Veröffentlichen" in /c/B and got a 403
            // — the shown-and-then-refused shape this whole task exists to remove, one level
            // further down. Scoped to the addressed organization, that population is offered
            // "Lesen" alone, which is all it may mint there.
            'may_publish_tokens' => $isMember && $user->administers($organization->id),
        ];
    }
}
