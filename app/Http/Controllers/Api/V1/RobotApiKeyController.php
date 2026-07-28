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
        // Nur Robot-Accounts dürfen über diesen Endpunkt einen API-Key erhalten — sonst
        // ließe sich für beliebige menschliche Nutzer ein unbefristeter Impersonation-Key
        // ausstellen, der deren 2FA/Passkey umgeht. Analog zum GUI-Pfad (Admin/RobotController
        // ::issueKey), hier 422 statt 404, da es eine fachliche Ablehnung im API-Kontext ist.
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
