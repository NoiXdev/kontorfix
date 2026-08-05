<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RobotController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/robots/Index', [
            'robots' => User::where('account_type', AccountType::Robot)->with('organization:id,name')->withCount('apiKeys')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role->value,
                    'is_super_admin' => (bool) $u->is_super_admin,
                    'organization' => $u->organization?->name,
                    'keys_count' => $u->api_keys_count,
                ]),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A robot is a service account — no mailbox, hence no email is collected. Its reach
        // is either a single organization (the home org + its per-org role) or global via
        // the super-admin flag, mirroring human accounts.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_super_admin' => ['boolean'],
        ]);

        $robot = User::create([
            'name' => $validated['name'],
            'email' => null,
            'organization_id' => $validated['organization_id'],
            'role' => $validated['role'],
            'is_super_admin' => $request->boolean('is_super_admin'),
            'account_type' => AccountType::Robot,
            'password' => null,
        ]);
        // No mailbox to verify, but keep the account out of any "unverified" state.
        $robot->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', "Robot {$robot->name} angelegt.");
    }

    public function issueKey(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->account_type === AccountType::Robot, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'permission' => ['required', Rule::enum(ApiKeyPermission::class)],
        ]);

        [, $plain] = ApiKey::issue($user, $validated['name'], ApiKeyPermission::from($validated['permission']));

        return back()->with('plainApiKey', $plain)->with('success', 'API-Key erstellt.');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->account_type === AccountType::Robot, 404);

        $user->delete();

        return back()->with('success', 'Robot gelöscht.');
    }
}
