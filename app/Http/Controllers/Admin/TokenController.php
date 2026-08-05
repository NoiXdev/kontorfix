<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TokenAbility;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Services\Scope\OrgScope;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TokenController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        $orgIds = $this->scopedOrgIds();

        return Inertia::render('admin/tokens/Index', [
            'tokens' => RegistryToken::with(['organization:id,name', 'group:id,name'])
                ->whereIn('organization_id', $orgIds)->latest()->get()
                ->map(fn (RegistryToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'organization' => $t->organization?->name,
                    'group' => $t->group?->name,
                    'ability' => $t->ability,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    'expires_at' => $t->expires_at?->toDateString(),
                ]),
            // Only organizations (and their registries) the user may administer.
            'organizations' => app(OrgScope::class)->organizations(),
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'organization_id']),
        ]);
    }

    public function store(StoreTokenRequest $request): RedirectResponse
    {
        // A token may only ever be issued for an organization the user administers; the
        // group (when set) is validated by the request to belong to that same org.
        $organizationId = $this->resolveCreationOrg($request->validated('organization_id'));

        [$token, $plain] = RegistryToken::issue(
            Organization::findOrFail($organizationId),
            $request->validated('name'),
            $request->validated('group_id') ? Group::findOrFail($request->validated('group_id')) : null,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
        );

        return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
    }

    public function destroy(RegistryToken $token): RedirectResponse
    {
        $this->assertAdministersOrg($token->organization_id);

        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }
}
