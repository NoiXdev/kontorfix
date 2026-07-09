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

        // Defense-in-depth: neben der org-scoped Rule im FormRequest hier zusätzlich
        // per Policy absichern, dass die Ziel-Registry der eigenen Org gehört.
        if ($group !== null) {
            $this->authorize('view', $group);
        }

        [$token, $plain] = RegistryToken::issue(
            $request->user()->organization,
            $request->validated('name'),
            $group,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
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
