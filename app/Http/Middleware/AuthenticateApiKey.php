<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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

        // read keys may only read.
        if ($key->permission === ApiKeyPermission::Read
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Dieser API-Key hat nur Leserechte.'], 403);
        }

        // Set the owner as the authenticated user → operator/role gates + policies take effect.
        Auth::setUser($key->user);
        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('apiKey', $key);

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        // Per-key rate limit — deliberately placed here (after key resolution), because
        // middlewarePriority would always sort an upstream throttle:api before this
        // middleware, which would then only throttle per IP instead of per key (global constraint: 120/min per key).
        $limiterKey = 'apikey:'.$key->getKey();
        if (RateLimiter::tooManyAttempts($limiterKey, 120)) {
            return response()->json(
                ['message' => 'Zu viele Anfragen.'],
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)],
            );
        }
        RateLimiter::hit($limiterKey, 60);

        return $next($request);
    }
}
