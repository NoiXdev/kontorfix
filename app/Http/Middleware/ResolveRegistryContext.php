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
            // Slug-Zugriff: /r/{slug}/...
            $group = Group::where('slug', $slug)->first();
            abort_if($group === null, 404);
            $request->attributes->set('registryGroup', $group);
            $request->attributes->set('registryDomainMode', false);

            // Controller-Actions kennen {groupSlug} nicht mehr als Parameter. Ohne dies
            // würde Laravels (rein positionale) Controller-Dispatch alle nachfolgenden
            // Routenparameter um eins verschieben.
            $request->route()->forgetParameter('groupSlug');
        } else {
            // Domain-Zugriff: Registry unter der Host-Wurzel. Unbekannter Host -> 404
            // (schützt die Haupt-App: fremde Hosts fallen sauber durch).
            $domain = Domain::where('hostname', $request->getHost())->first();
            abort_if($domain === null, 404);
            $request->attributes->set('registryGroup', $domain->group);
            $request->attributes->set('registryDomainMode', true);
        }

        return $next($request);
    }
}
