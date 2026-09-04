<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StorePortalTokenRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
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
        $organization = $this->portalOrganization($request);

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
        abort_unless(
            in_array($organization->id, $request->user()->accessibleOrganizationIds(), true),
            403,
        );

        $group = $request->validated('group_id')
            ? Group::findOrFail($request->validated('group_id'))
            : null;

        // Defense-in-depth: in addition to the org-scoped rule in the FormRequest, also
        // enforce via policy here that the target registry belongs to the own org. This is
        // the only caller that reaches GroupPolicy::view() without a portal_enabled check of
        // its own, so the policy's own clause is load-bearing here and nowhere else.
        if ($group !== null) {
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

    public function destroy(RegistryToken $token): RedirectResponse
    {
        $this->authorize('delete', $token);
        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }

    /** The organization the address names, put on the request by ResolvePortalContext. */
    private function portalOrganization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('portalOrganization');

        return $organization;
    }
}
