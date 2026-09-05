<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StorePortalTokenRequest;
use App\Models\Group;
use App\Models\RegistryToken;
use App\Services\Portal\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function store(StorePortalTokenRequest $request): RedirectResponse
    {
        // The organization the URL addresses, read the way RegistryController reads it,
        // rather than re-derived from the submitted group or from the caller's home
        // organization. The home-org fallback predates /c/{orgSlug}: with an address in the
        // URL it minted a token for the wrong organization whenever a member of two
        // organizations submitted no group.
        $organization = PortalContext::get($request);

        // Minting requires membership, not visibility. ResolvePortalContext deliberately lets
        // an operator account open any customer portal so that support sees what the customer
        // sees; letting that same access issue a token would turn looking into a way of
        // obtaining credentials for someone else's registry.
        //
        // 403, not the 404 the hidden-registry guard gives: a hidden registry is absent from
        // the portal for everyone, while an operator standing in a customer portal is
        // somewhere they are allowed to be, doing something they are not allowed to do.
        //
        // Ahead of the 'create' authorization, and not folded into it: Gate::before answers
        // true for a super-admin before RegistryTokenPolicy::create is ever consulted, so the
        // policy's own membership clause cannot carry this. Ahead of RegistryToken::issue in
        // any case — a refusal that still wrote the row is not a refusal.
        //
        // belongsToOrganization(), which is the ONE statement of the membership question and
        // the one RegistryTokenPolicy::create() asks below. The portal's `may_mint_tokens`
        // prop hides the form on exactly this answer, so the two cannot be made to disagree
        // by editing one of two copies of an in_array.
        abort_unless($request->user()->belongsToOrganization($organization->id), 403);

        $group = $request->validated('group_id')
            ? Group::findOrFail($request->validated('group_id'))
            : null;

        if ($group !== null) {
            // Stated here, BEFORE the policy, exactly as RegistryController states it for its
            // two read actions — and for the same reason, which this surface used to get
            // wrong. "Does this registry appear in the portal" is a property of the surface,
            // not of the viewer, and a policy cannot hold that: Gate::before short-circuits
            // GroupPolicy::view() for a super-admin, so its portal_enabled clause is never
            // read for them. Leaving it to the policy meant a super-admin could mint a token
            // for a collection-only group in their own organization's portal while every
            // other population got a refusal. 404, matching the read actions: a registry the
            // portal does not show is one the portal does not have, whoever is asking.
            //
            // The policy keeps its own clause. It is no longer the last line on any path, but
            // it still orders that check ahead of the policy's operator branch — see the
            // comment on GroupPolicy::view(), which is about the inside of the policy.
            abort_unless($group->portal_enabled, 404);
            // Defense-in-depth: in addition to the org-scoped rule in the FormRequest, also
            // enforce via policy here that the target registry belongs to the own org.
            $this->authorize('view', $group);
            // The URL names the organization; a group from a different one would make it mean
            // nothing, even where the user happens to belong to both. The FormRequest rule
            // catches the groups of organizations the caller does not belong to at all and
            // reports them as a validation error; this catches the rest, where there is
            // nothing to report on a field the caller was entitled to fill in.
            abort_unless($group->organization_id === $organization->id, 403);
        }

        $ability = $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read;

        // A publish token writes into the organization's registries, so it is
        // admin/maintainer-only — a member may only ever mint a read token.
        $this->authorize('create', [RegistryToken::class, $organization->id, $ability]);

        [$token, $plain] = RegistryToken::issue(
            $organization,
            $request->validated('name'),
            $group,
            $ability,
            $request->date('expires_at'),
            $request->user(),
        );

        return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
    }

    /**
     * Bound to the addressed organization the way store() binds its submitted group, and for
     * the same reason: /c/{orgSlug} names an organization, and a route that acts on a token
     * belonging to a different one makes that segment mean nothing. Before this,
     * DELETE /c/{orgA}/tokens/{token-of-orgB} succeeded for anybody RegistryTokenPolicy
     * already allows.
     *
     * No privilege is gained or lost by it. The policy is untouched, and every caller refused
     * here was already able to revoke the same token through its own organization's portal or
     * through the console — this is the URL's coherence rule, not an authorization rule.
     *
     * BEFORE authorize(), exactly where store() puts the group's binding check. The question
     * is a property of the address rather than of the viewer, and AppServiceProvider's
     * Gate::before waves a super-admin past the policy entirely — asked afterwards it would be
     * the one population for whom the URL still meant nothing. Asked first, everybody gets the
     * same answer. And ahead of the delete in any case: a refusal that still removed the row
     * is not a refusal.
     *
     * 403 rather than 404, matching store()'s cross-organization group: the token exists and
     * the caller may well be entitled to it, just not through this address. The uniform-404
     * rule protects organization SLUGS from being guessed, and the slug in this URL has
     * already been resolved by ResolvePortalContext before this runs.
     *
     * NO membership guard, deliberately, and that is the one thing store() has that this does
     * not. Spec §4 requires membership for MINTING — "looking in to help" must not double as a
     * way of issuing oneself credentials — and revocation is the opposite act: it takes a
     * credential away. Requiring membership here would only stop an operator from revoking a
     * customer's leaked token from the surface they are already looking at, which no decision
     * in the spec asks for.
     */
    public function destroy(Request $request, RegistryToken $token): RedirectResponse
    {
        $organization = PortalContext::get($request);

        abort_unless($token->organization_id === $organization->id, 403);

        $this->authorize('delete', $token);
        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }
}
