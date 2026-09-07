<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\RegistryToken;
use App\Services\Portal\PortalContext;
use App\Services\Portal\PortalPackages;
use App\Services\Portal\PortalRegistryAssignment;
use App\Services\Registry\RegistryTypeService;
use App\Services\Registry\RegistryUrl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function __construct(
        private readonly PortalPackages $packages,
        // The one source for a registry's address. The entry band names it, and every other
        // surface that shows one asks here — a second way to assemble it is how an operator
        // and their customer end up reading two spellings of one URL.
        private readonly RegistryUrl $url,
        // Which ecosystems the entry band's second step may name. The instance-wide ceiling
        // intersected with the organization's own restriction — the same answer
        // Portal\RegistryController::show() builds the Einrichtung tab from, so the band and
        // the page its button leads to name one set of tools rather than two.
        private readonly RegistryTypeService $types,
    ) {}

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
     *
     * It also carries the ENTRY BAND's payload — the registries with their addresses and the
     * organization's token state — because the band sits above this list on this page. See the
     * two blocks below it for why the state is a server answer and why it is one query.
     */
    public function index(Request $request): Response
    {
        $organization = PortalContext::get($request);

        $rows = $this->packages->for($organization);

        // One query for every row's versions instead of one per row. The service answers with
        // MODELS rather than a query — it composes its set from several registries — so the
        // eager load belongs here, and an Eloquent collection is what carries it.
        //
        // No ordering closure: Package::versions() is declared `->orderByDesc('released_at')`,
        // so first() is the newest release wherever the relation is loaded. Repeating the order
        // here (as RegistryController::show() does) would be a second statement of it, and the
        // one that silently stops matching when the relation's own order changes.
        (new EloquentCollection($rows->pluck('package')->all()))->load('versions');

        // The registries the entry band picks from, with their addresses. `portal_enabled` is
        // the same per-registry predicate PortalPackages and Portal\RegistryController::index()
        // ask of the same column — a registry the portal hides must not become the target of a
        // button on the portal's own landing page, which GroupPolicy::view() would then answer
        // 403 to. Ordered by name, so the band's default pick and the registries page agree.
        //
        // `domains` eager-loaded because RegistryUrl::base() reads it in the custom-domain
        // branch and this is a loop. `organization` is NOT loaded: base() also reads it, for
        // the slug in the canonical path, and it is the row already in hand — so the relation
        // is set rather than fetched a second time, the trick ResolveRegistryContext states
        // for the same call and the same reason. The relation is genuinely this object; these
        // groups came out of the organization's own hasMany.
        $registries = $organization->groups()
            ->where('portal_enabled', true)
            ->with('domains')
            ->orderBy('name')
            ->get();

        $registries->each(fn (Group $g) => $g->setRelation('organization', $organization));

        // THE COLLAPSE RULE, IN ONE QUERY AND ONE ROW — never one query per registry, which is
        // what asking each registry for its own tokens would have made of it.
        //
        // The band collapses once a token OF THIS ORGANIZATION has been used (spec §3.1). Read
        // from `last_used_at` and never from a dismissal flag in browser storage: a per-browser
        // flag disagrees between the customer's laptop and their CI machine, and the question
        // "is this customer connected" has one answer for both.
        //
        // The row is the organization's most recently used token, and the three states fall out
        // of it without a second query: no row at all is `none`, a row whose `last_used_at` is
        // null is `unused` (nulls sort last, so if the first row has none, none do), and
        // anything else is `used`.
        //
        // THREE ORDERING CLAUSES, and the last one is what makes the pick reproducible.
        // `created_at` separates tokens minted on different SECONDS and no finer — the column
        // is `timestamp(0)`, which is what `$table->timestamps()` writes — so two tokens minted
        // in one request are tied on it, and the row PostgreSQL then hands back is whichever
        // the scan reached first. `id` is unique and the schema can distinguish it, so the
        // customer reads the same name on every reload instead of a name that changes under
        // them. It is an arbitrary pick between equals, but a STABLE one.
        //
        // NOT REVOKED AND NOT EXPIRED — the liveness predicate findByPlainText() resolves by,
        // minus the entitlement check it can only make in PHP. The band asks whether the
        // customer can reach their packages right now, and a credential the registry refuses
        // does not answer yes: an organization whose only token was revoked is shown the way to
        // a new one rather than a line telling it everything is set up.
        $token = RegistryToken::query()
            ->where('organization_id', $organization->id)
            ->notRevoked()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('last_used_at desc nulls last')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->first();

        $lastUsedAt = $token?->last_used_at;

        return Inertia::render('portal/Packages', [
            // The addressed organization, not the viewer's own: an operator looking at a
            // customer's portal must navigate inside that customer's portal.
            'orgSlug' => $organization->slug,
            'registries' => $registries->map(fn (Group $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'url' => $this->url->base($g),
            ])->values(),
            // The ecosystems the band's second step may name. It used to name all four from
            // plate 1 whatever the organization was permitted to use, and then sent the
            // customer to a page that shows only the permitted ones — or says none are
            // enabled. Naming four and showing one is the claim task 3 existed to remove.
            'setupTypes' => $this->types->effectiveFor($organization),
            // Sent finished rather than as the raw column, so the browser holds no second
            // statement of what a used token is. portalSetupBand.ts maps this onto the band
            // and deliberately does not re-derive it from `lastUsedToken` below.
            'setupState' => match (true) {
                $token === null => 'none',
                $lastUsedAt === null => 'unused',
                default => 'used',
            },
            // Display payload for the collapsed line, null in the other two states. The name
            // is the only part of that line the customer can act on — it says WHICH credential
            // their build is running on, not merely that one exists.
            //
            // `locale('de')` explicitly, not the application's: `app.locale` is `en` on this
            // instance, and diffForHumans() then puts "2 hours ago" in the middle of a German
            // sentence. The console is German throughout whatever the framework's locale is.
            'lastUsedToken' => $token === null || $lastUsedAt === null ? null : [
                'name' => $token->name,
                'used_at' => $lastUsedAt->locale('de')->diffForHumans(),
            ],
            'packages' => $rows->map(fn (array $row): array => [
                'id' => $row['package']->id,
                'name' => $row['package']->name,
                'type' => $row['package']->type->value,
                'description' => $row['package']->description,
                // The package's own newest release, not a per-registry answer: a registry
                // serves the versions the package has, and spec §3 asks the landing page for
                // the current one beside the type.
                'latest_version' => $row['package']->versions->first()?->version_pretty,
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

        // The dead end the portal switch leaves behind. ResolvePortalContext answers 404 for
        // an organization whose portal is off — deliberately, and uniformly with the slug it
        // will not confirm — so redirecting into /c/{slug} sends a plain member to a 404 and
        // /dashboard sends them here first. That account then has no reachable page at all.
        //
        // A SIBLING PAGE rather than a second variant of portal/NoOrganization: the two say
        // different things (that page's name is a false statement about an account that HAS
        // an organization), and a variant prop would let both cases satisfy one
        // `component()` assertion, so a test could no longer tell which branch it hit.
        //
        // Naming the organization is safe here in a way it is not at the gate: the viewer is
        // a member and already knows it exists. The uniform 404 protects slugs from being
        // guessed by strangers, and this account is not one.
        if (! $organization->portal_enabled) {
            return Inertia::render('portal/PortalDisabled', ['organization' => $organization->name]);
        }

        return redirect()->route('portal.packages.index', $organization->slug);
    }
}
