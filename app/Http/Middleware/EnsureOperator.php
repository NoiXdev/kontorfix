<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->organization?->is_operator, 403);

        return $next($request);
    }
}
