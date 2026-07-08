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

        // Gedrosselt: ein composer install trifft dutzende Endpoints — last_used_at
        // ist eine Heuristik und braucht keine Sekunden-Präzision.
        if ($token && ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute()))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('registryToken', $token); // null = anonym; ACL entscheidet später

        return $next($request);
    }
}
