<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with('organization:id,name')->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'organization' => $user->organization?->name,
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
        $user = User::create($request->validated());
        $user->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', "Nutzer {$user->name} angelegt.");
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
