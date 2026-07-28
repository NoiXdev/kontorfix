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
        $slug = $request->route('groupSlug');

        if ($slug !== null) {
            // Slug access: /r/{slug}/...
            $group = Group::where('slug', $slug)->first();
            abort_if($group === null, 404);
            $request->attributes->set('registryGroup', $group);
            $request->attributes->set('registryDomainMode', false);

            // Controller actions no longer know {groupSlug} as a parameter. Without this,
            // Laravel's (purely positional) controller dispatch would shift all subsequent
            // route parameters by one.
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
