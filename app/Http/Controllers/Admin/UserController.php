<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Services\RegistryTokenLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly RegistryTokenLifecycleService $tokenLifecycle) {}

    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with(['organization:id,name', 'organizations:id,name'])->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'is_super_admin' => (bool) $user->is_super_admin,
                    'organization_id' => $user->organization_id,
                    'organization' => $user->organization?->name,
                    // Additional memberships beyond the home org, each with its per-org role.
                    'memberships' => $user->organizations->map(fn (Organization $org) => [
                        'id' => $org->id,
                        'name' => $org->name,
                        'role' => $org->getRelationValue('pivot')?->getAttribute('role') ?? UserRole::Member->value,
                    ])->values(),
                ]),
            'organizations' => Organization::orderBy('name')->get(['id', 'name'])
                ->map(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                ]),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $withPassword = $request->filled('password');

        if (! $withPassword) {
            $validated['password'] = Str::random(40);
        }

        $user = User::create($validated);
        $user->forceFill(['email_verified_at' => now()])->save();

        if ($withPassword) {
            return back()->with('success', "Nutzer {$user->name} angelegt.");
        }

        $user->notify(new UserInvitation);

        return back()->with('success', "Nutzer {$user->name} eingeladen.");
    }

    public function invite(User $user): RedirectResponse
    {
        $user->notify(new UserInvitation);

        return back()->with('success', 'Einladung erneut gesendet.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        // Never let the last remaining super-admin lose global access — that would lock
        // everyone out of instance administration.
        if ($user->isSuperAdmin()
            && ! $this->wouldRemainSuperAdmin($user, $validated)
            && $this->effectiveSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Der letzte Super-Admin kann nicht herabgestuft werden.',
            ]);
        }

        $user->update($validated);

        // A changed home organization or role can strip the access a personal registry
        // token was issued under.
        $this->tokenLifecycle->revokeUnentitled($user);

        return back()->with('success', "Nutzer {$user->name} aktualisiert.");
    }

    public function attachOrganization(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        // The home org is already an implicit membership — attaching it again would be
        // a redundant (and confusing) pivot row.
        if ($data['organization_id'] === $user->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => 'Das ist bereits die Heim-Organisation des Nutzers.',
            ]);
        }

        // Per-org role for the membership (defaults to member).
        $role = $data['role'] ?? UserRole::Member->value;
        $user->organizations()->syncWithoutDetaching([$data['organization_id'] => ['role' => $role]]);

        return back()->with('success', 'Organisation zugewiesen.');
    }

    public function detachOrganization(User $user, Organization $organization): RedirectResponse
    {
        $user->organizations()->detach($organization->id);

        // Personal tokens for an organization the user has left are dead credentials.
        $this->tokenLifecycle->revokeUnentitled($user);

        return back()->with('success', 'Organisation entfernt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Du kannst dich nicht selbst löschen.',
            ]);
        }

        if ($user->isSuperAdmin() && $this->effectiveSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Der letzte Super-Admin kann nicht gelöscht werden.',
            ]);
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "Nutzer {$name} gelöscht.");
    }

    /**
     * Whether the user would still be an effective super-admin after applying the given
     * validated changes (explicit flag, or admin of the operator organization).
     *
     * @param  array<string, mixed>  $validated
     */
    private function wouldRemainSuperAdmin(User $user, array $validated): bool
    {
        $flag = array_key_exists('is_super_admin', $validated)
            ? (bool) $validated['is_super_admin']
            : (bool) $user->is_super_admin;

        if ($flag) {
            return true;
        }

        $role = array_key_exists('role', $validated) ? $validated['role'] : $user->role->value;
        $orgId = $validated['organization_id'] ?? $user->organization_id;
        $org = $orgId === $user->organization_id ? $user->organization : Organization::find($orgId);

        return $role === UserRole::Admin->value && (bool) $org?->is_operator;
    }

    /** Number of accounts that currently have effective super-admin reach. */
    private function effectiveSuperAdminCount(): int
    {
        return User::with('organization')->get()->filter(fn (User $u) => $u->isSuperAdmin())->count();
    }
}
