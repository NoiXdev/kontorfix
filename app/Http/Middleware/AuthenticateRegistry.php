<?php

namespace App\Http\Middleware;

use App\Models\RegistryToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRegistry
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->bearerToken()                       // npm: Authorization: Bearer <token>
            ?: ($request->getPassword() ?: $request->getUser());  // composer: HTTP Basic
        $token = $candidate ? RegistryToken::findByPlainText($candidate) : null;

        // Throttled: a composer install hits dozens of endpoints — last_used_at
        // is a heuristic and doesn't need second-level precision.
        if ($token && ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute()))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('registryToken', $token); // null = anonymous; the ACL decides later

        return $next($request);
    }
}
