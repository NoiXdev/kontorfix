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
                ->where('user_id', $user->id)
                ->with('group:id,name')
                ->latest()->get()
                ->map(fn (RegistryToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'ability' => $t->ability->value,
                    'group' => $t->group?->name,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    'expires_at' => $t->expires_at?->toDateString(),
                ]),
            'groups' => $user->organization_id
                ? Group::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(StoreAccessTokenRequest $request): RedirectResponse
    {
        $user = $request->user();

        $group = $request->validated('group_id')
            ? Group::where('organization_id', $user->organization_id)->findOrFail($request->validated('group_id'))
            : null;

        [$token, $plain] = RegistryToken::issue(
            $user->organization,
            $request->validated('name'),
            $group,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
            null,
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
