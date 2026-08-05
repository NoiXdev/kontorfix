<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $request->query('account_type');

        return UserResource::collection(
            User::query()
                ->when(in_array($type, ['human', 'robot'], true), fn ($q) => $q->where('account_type', $type))
                ->orderBy('name')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isRobot = ($validated['account_type'] ?? 'human') === AccountType::Robot->value;

        // Robots have no password; humans without a password get a random password.
        if ($isRobot) {
            $validated['password'] = null;
        } elseif (empty($validated['password'])) {
            $validated['password'] = Str::random(40);
        }

        $user = User::create($validated);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        // Never let the last remaining super-admin lose global access.
        if ($user->isSuperAdmin()
            && ! $this->wouldRemainSuperAdmin($user, $validated)
            && $this->effectiveSuperAdminCount() <= 1) {
            throw ValidationException::withMessages(['role' => 'Der letzte Super-Admin kann nicht herabgestuft werden.']);
        }

        $user->update($validated);

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Du kannst dich nicht selbst löschen.']);
        }

        if ($user->isSuperAdmin() && $this->effectiveSuperAdminCount() <= 1) {
            throw ValidationException::withMessages(['user' => 'Der letzte Super-Admin kann nicht gelöscht werden.']);
        }

        $user->delete();

        return response()->json(status: 204);
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
