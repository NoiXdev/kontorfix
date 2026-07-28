<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiKeys', [
            'apiKeys' => $request->user()->apiKeys()->latest()->get()
                ->map(fn (ApiKey $k) => [
                    'id' => $k->id,
                    'name' => $k->name,
                    'permission' => $k->permission->value,
                    'last_used_at' => $k->last_used_at?->diffForHumans(),
                    'expires_at' => $k->expires_at?->toDateString(),
                ]),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        [$key, $plain] = ApiKey::issue(
            $request->user(),
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        return back()->with('plainApiKey', $plain)->with('success', "API-Key {$key->name} erstellt.");
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);
        $apiKey->delete();

        return back()->with('success', 'API-Key widerrufen.');
    }
}
