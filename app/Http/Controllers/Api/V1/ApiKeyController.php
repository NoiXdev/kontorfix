<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Http\Resources\Api\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            $request->user()->apiKeys()->latest()->get()
        );
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$key, $plain] = ApiKey::issue(
            $request->user(),
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        $key->plain_text = $plain;

        return (new ApiKeyResource($key))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);
        $apiKey->delete();

        return response()->json(status: 204);
    }
}
