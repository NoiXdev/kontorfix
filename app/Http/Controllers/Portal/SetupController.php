<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\RegistryToken;
use App\Services\Portal\PortalContext;
use App\Services\Registry\RegistryTypeService;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal's organization-wide "Einrichtung" tab (Task 7) — the single address a customer
 * with several registries can point every client at once, backed by an org-wide token
 * (`RegistryToken::issue(..., group: null, ...)`) rather than one scoped to a single
 * registry.
 *
 * This is a SEPARATE surface from RegistryController::show()'s own Einrichtung tab, not a
 * replacement for it: that tab still exists per registry, for the reader who wants exactly
 * one address and a token that can reach nowhere else. This one trades that narrower reach
 * for reading everywhere the org-wide token can, in one snippet set.
 */
class SetupController extends Controller
{
    public function __construct(
        private SetupSnippetBuilder $snippets,
        private RegistryTypeService $types,
    ) {}

    public function show(Request $request): Response
    {
        $organization = PortalContext::get($request);

        return Inertia::render('portal/Setup', [
            'orgSlug' => $organization->slug,
            // The org-wide twin of RegistryController::show()'s `snippets` — see
            // SetupSnippetBuilder::forOrganization()'s doc comment for the three ways it
            // differs from the per-group builder (no twine, a Docker section built from
            // every one of the organization's groups instead of one, and every remaining
            // section gated by `types` below rather than merely filtered by it).
            'snippets' => $this->snippets->forOrganization($organization),
            'types' => $this->types->effectiveFor($organization),
            // The caller's OWN org-wide tokens — `group_id IS NULL`, the same predicate
            // RegistryToken::issue() writes when store() receives no `group_id` at all. NOT
            // every org-wide token of the organization: RegistryController::show() already
            // scopes its own list to `where('user_id', $request->user()->id)`, and this tab
            // is that same "your tokens, on this surface" rule applied to the org-wide
            // scope instead of one group's. A colleague's org-wide token is exactly as
            // invisible here as their group-bound one is on the registry page.
            'tokens' => $organization->registryTokens()
                ->whereNull('group_id')
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (RegistryToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'ability' => $t->ability->value,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    // Raw ISO timestamp for sorting only — see RegistryController::show()'s
                    // identical field, which this list shares its DataTable column with.
                    'last_used_at_iso' => $t->last_used_at?->toIso8601String(),
                ]),
            // Whether the "Veröffentlichen" ability may be offered on THIS tab's mint form.
            // Asked of the policy directly — RegistryTokenPolicy::create(), the same rule
            // TokenController::store() enforces on submit — rather than read off the shared
            // `portal.may_publish_tokens` prop the registry tab's Vue reads: this tab has no
            // group in hand at all, and asking the policy here keeps the controller's own
            // payload the one place this page's authorization decision is made, independent
            // of a page prop other controllers do not populate.
            'can_publish' => $request->user()->can('create', [RegistryToken::class, $organization->id, TokenAbility::Publish]),
        ]);
    }
}
