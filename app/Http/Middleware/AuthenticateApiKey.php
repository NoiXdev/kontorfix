<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $key = $plain ? ApiKey::findByPlainText($plain) : null;

        if ($key === null || $key->user === null) {
            return response()->json(['message' => 'Ungültiger oder fehlender API-Key.'], 401);
        }

        // read-Keys dürfen ausschließlich lesen.
        if ($key->permission === ApiKeyPermission::Read
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Dieser API-Key hat nur Leserechte.'], 403);
        }

        // Besitzer als authentifizierten Nutzer setzen → operator/role-Gates + Policies greifen.
        Auth::setUser($key->user);
        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('apiKey', $key);

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
