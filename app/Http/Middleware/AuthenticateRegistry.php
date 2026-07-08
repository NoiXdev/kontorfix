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
        $candidate = $request->getPassword() ?: $request->getUser();
        $token = $candidate ? RegistryToken::findByPlainText($candidate) : null;

        $token?->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('registryToken', $token); // null = anonym; ACL entscheidet später

        return $next($request);
    }
}
