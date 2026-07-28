<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Http\Resources\Api\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RobotApiKeyController extends Controller
{
    public function store(StoreApiKeyRequest $request, User $user): JsonResponse
    {
        // Only robot accounts may obtain an API key via this endpoint — otherwise
        // an unlimited-lifetime impersonation key could be issued for any human user,
        // bypassing their 2FA/passkey. Analogous to the GUI path (Admin/RobotController
        // ::issueKey), here 422 instead of 404, since it's a business-logic rejection in the API context.
        abort_unless($user->isRobot(), 422);

        [$key, $plain] = ApiKey::issue(
            $user,
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        $key->plain_text = $plain;

        return (new ApiKeyResource($key))->response()->setStatusCode(201);
    }
}
