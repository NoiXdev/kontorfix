<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
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

        // Robots haben kein Passwort; Menschen ohne Passwort erhalten ein Zufalls-Passwort.
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

        if ($user->role === UserRole::Admin
            && $user->organization->is_operator
            && ($validated['role'] ?? null) !== UserRole::Admin->value
            && $user->organization->users()->where('role', UserRole::Admin->value)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Der letzte Betreiber-Admin kann nicht herabgestuft werden.']);
        }

        $user->update($validated);

        return new UserResource($user);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(status: 204);
    }
}
