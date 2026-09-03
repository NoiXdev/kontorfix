<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Group;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRegistryContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $orgSlug = $request->route('orgSlug');
        $slug = $request->route('groupSlug');

        if ($slug !== null) {
            // Slug access: /r/{orgSlug}/{groupSlug}/... A slug is unique only within its
            // organization, so both segments are part of the lookup.
            $group = Group::where('slug', $slug)
                ->whereHas('organization', fn ($q) => $q->where('slug', $orgSlug))
                ->first();
            abort_if($group === null, 404);
            $request->attributes->set('registryGroup', $group);
            $request->attributes->set('registryDomainMode', false);

            // Controller actions know neither parameter. Without this, Laravel's purely
            // positional controller dispatch would shift every later route parameter.
            $request->route()->forgetParameter('orgSlug');
            $request->route()->forgetParameter('groupSlug');
        } else {
            // Domain access: registry at the host root. Unknown host -> 404
            // (protects the main app: foreign hosts fall through cleanly).
            $domain = Domain::where('hostname', $request->getHost())->first();
            abort_if($domain === null, 404);
            $request->attributes->set('registryGroup', $domain->group);
            $request->attributes->set('registryDomainMode', true);
        }

        return $next($request);
    }
}
