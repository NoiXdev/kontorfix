<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccessTokenRequest;
use App\Models\Group;
use App\Models\RegistryToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/AccessTokens', [
            'tokens' => RegistryToken::query()
                ->notRevoked()
                ->where('user_id', $user->id)
                ->with('group:id,name')
                ->latest()->get()
                ->map(fn (RegistryToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'ability' => $t->ability->value,
                    'group' => $t->group?->name,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    // Raw ISO timestamp for sorting only — `last_used_at` above is a relative
                    // string ("vor 3 Tagen") that Date.parse cannot read, so the display value
                    // and the sort value have to travel separately.
                    'last_used_at_iso' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toDateString(),
                ]),
            'groups' => Group::whereIn('organization_id', $user->accessibleOrganizationIds())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreAccessTokenRequest $request): RedirectResponse
    {
        $user = $request->user();

        $group = $request->validated('group_id')
            ? Group::whereIn('organization_id', $user->accessibleOrganizationIds())->findOrFail($request->validated('group_id'))
            : null;

        // Tie the token to the group's organization (possibly an additional-membership
        // org), falling back to the home org for an org-wide token.
        $organization = $group !== null ? $group->organization : $user->organization;
        $ability = $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read;

        // A publish token writes into the organization's registries, so it is
        // admin/maintainer-only — a member may only ever mint a read token.
        $this->authorize('create', [RegistryToken::class, $organization?->id, $ability]);

        [$token, $plain] = RegistryToken::issue(
            $organization,
            $request->validated('name'),
            $group,
            $ability,
            $request->date('expires_at'),
            $user,
        );

        return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
    }

    public function destroy(Request $request, RegistryToken $token): RedirectResponse
    {
        abort_unless($token->user_id === $request->user()->id, 403);
        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }
}
