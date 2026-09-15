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
                    // Raw ISO timestamp for sorting only — `last_used_at` above is a relative
                    // string ("vor 3 Tagen") that Date.parse cannot read, so the display value
                    // and the sort value have to travel separately.
                    'last_used_at_iso' => $k->last_used_at?->toIso8601String(),
                    'expires_at' => $k->expires_at?->toDateString(),
                    // Shown greyed out rather than hidden: a revoked row that vanished from
                    // the listing would look identical to the hard delete this replaced, and
                    // the point of keeping the row is that the owner can see it happened.
                    'revoked' => $k->revoked_at !== null,
                    'revoked_at' => $k->revoked_at?->diffForHumans(),
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

        // Revoked, not deleted — see the migration. findByPlainText() refuses a revoked key,
        // so this is as final for authentication as the delete it replaced, while leaving a
        // record that the credential existed and was withdrawn.
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'API-Key widerrufen.');
    }
}
