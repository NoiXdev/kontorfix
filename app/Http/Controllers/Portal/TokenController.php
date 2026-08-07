<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StorePortalTokenRequest;
use App\Models\Group;
use App\Models\RegistryToken;
use Illuminate\Http\RedirectResponse;

class TokenController extends Controller
{
    public function store(StorePortalTokenRequest $request): RedirectResponse
    {
        $group = $request->validated('group_id')
            ? Group::findOrFail($request->validated('group_id'))
            : null;

        // Defense-in-depth: in addition to the org-scoped rule in the FormRequest, also
        // enforce via policy here that the target registry belongs to the own org.
        if ($group !== null) {
            $this->authorize('view', $group);
        }

        // Tie the token to the target group's organization (which may be an org the
        // user only has additional membership in), falling back to the home org.
        $organization = $group !== null ? $group->organization : $request->user()->organization;
        $ability = $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read;

        // A publish token writes into the organization's registries, so it is
        // admin/maintainer-only — a member may only ever mint a read token.
        $this->authorize('create', [RegistryToken::class, $organization?->id, $ability]);

        [$token, $plain] = RegistryToken::issue(
            $organization,
            $request->validated('name'),
            $group,
            $ability,
            null,
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
}
