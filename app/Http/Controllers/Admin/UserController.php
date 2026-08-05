<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with(['organization:id,name', 'organizations:id,name'])->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'organization_id' => $user->organization_id,
                    'organization' => $user->organization?->name,
                    // Additional memberships beyond the home org.
                    'memberships' => $user->organizations->map(fn (Organization $org) => [
                        'id' => $org->id,
                        'name' => $org->name,
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

        if ($user->role === UserRole::Admin
            && $user->organization->is_operator
            && ($validated['role'] ?? null) !== UserRole::Admin->value
            && $user->organization->users()->where('role', UserRole::Admin->value)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Der letzte Betreiber-Admin kann nicht herabgestuft werden.',
            ]);
        }

        $user->update($validated);

        return back()->with('success', "Nutzer {$user->name} aktualisiert.");
    }

    public function attachOrganization(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);

        // The home org is already an implicit membership — attaching it again would be
        // a redundant (and confusing) pivot row.
        if ($data['organization_id'] === $user->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => 'Das ist bereits die Heim-Organisation des Nutzers.',
            ]);
        }

        $user->organizations()->syncWithoutDetaching([$data['organization_id']]);

        return back()->with('success', 'Organisation zugewiesen.');
    }

    public function detachOrganization(User $user, Organization $organization): RedirectResponse
    {
        $user->organizations()->detach($organization->id);

        return back()->with('success', 'Organisation entfernt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Du kannst dich nicht selbst löschen.',
            ]);
        }

        if ($user->role === UserRole::Admin
            && $user->organization->is_operator
            && $user->organization->users()->where('role', UserRole::Admin->value)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Der letzte Betreiber-Admin kann nicht gelöscht werden.',
            ]);
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "Nutzer {$name} gelöscht.");
    }
}
