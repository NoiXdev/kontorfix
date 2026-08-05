<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/organizations/Index', [
            'organizations' => Organization::withCount(['users', 'groups'])->orderBy('name')->get()
                ->map(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'slug' => $org->slug,
                    'is_operator' => $org->is_operator,
                    'users_count' => $org->users_count,
                    'groups_count' => $org->groups_count,
                ]),
        ]);
    }

    public function show(Organization $organization): Response
    {
        return Inertia::render('admin/organizations/Show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'is_operator' => $organization->is_operator,
            ],
            'registries' => $organization->groups()->withCount('packages')->with('domains:id,group_id,hostname')->get()
                ->map(fn (Group $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'packages_count' => $group->packages_count,
                    'domains' => $group->domains->map(fn (Domain $domain) => $domain->hostname)->values(),
                ]),
            'users' => $organization->users()->get(['id', 'name', 'email', 'role', 'is_super_admin'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'is_super_admin' => (bool) $user->is_super_admin,
                ]),
            // Users with additional (non-home) access to this organization, each with the
            // per-organization role their membership grants.
            'members' => $organization->members()->get(['users.id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRelationValue('pivot')?->getAttribute('role') ?? UserRole::Member->value,
                ]),
            // Candidates for granting additional access: everyone whose home org is a
            // different one and who is not already an additional member here.
            'assignableUsers' => User::where('organization_id', '!=', $organization->id)
                ->whereDoesntHave('organizations', fn ($q) => $q->whereKey($organization->id))
                ->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]),
            'tokens' => $organization->registryTokens()->with('group:id,name')->get()
                ->map(fn (RegistryToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'ability' => $token->ability->value,
                    'group' => $token->group?->name,
                ]),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $org = Organization::create([
            ...$request->validated(),
            'is_operator' => false,
        ]);

        return back()->with('success', "Kunde {$org->name} angelegt.");
    }

    public function attachMember(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $user = User::findOrFail($data['user_id']);

        if ($user->organization_id === $organization->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Dieser Nutzer gehört bereits fest zu dieser Organisation.',
            ]);
        }

        // The per-organization role this membership grants (defaults to member).
        $role = $data['role'] ?? UserRole::Member->value;
        $organization->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);

        return back()->with('success', "{$user->name} hinzugefügt.");
    }

    public function detachMember(Organization $organization, User $user): RedirectResponse
    {
        $organization->members()->detach($user->id);

        return back()->with('success', "{$user->name} entfernt.");
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        if ($organization->is_operator || $organization->users()->exists() || $organization->groups()->exists()) {
            throw ValidationException::withMessages([
                'organization' => 'Organisation ist Betreiber oder nicht leer (erst Registries/Nutzer entfernen).',
            ]);
        }

        $name = $organization->name;
        $organization->delete();

        return back()->with('success', "Kunde {$name} gelöscht.");
    }
}
