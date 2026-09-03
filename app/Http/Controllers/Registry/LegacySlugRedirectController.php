<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Registry\RegistryUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answers the pre-org-scoped /r/{slug}/... URLs that live in customers' composer.json,
 * .npmrc and pip.conf. Composer, npm and pip all follow redirects, so an existing client
 * keeps working and sees the canonical URL in its logs.
 *
 * Registered AFTER the canonical route: Laravel matches the first route that fits, so
 * /r/{org}/{group} always wins where both could. The migration refuses an organization
 * slug that equals a registry slug, which is the only case where that precedence would
 * silently answer with the wrong registry.
 */
class LegacySlugRedirectController extends Controller
{
    public function __invoke(Request $request, RegistryUrl $urls, string $groupSlug, string $rest = ''): RedirectResponse
    {
        $group = Group::with('organization')->where('slug', $groupSlug)->first();
        abort_if($group === null, 404);

        $target = $urls->path($group).($rest === '' ? '' : '/'.$rest);
        $query = $request->getQueryString();

        return redirect($query === null ? $target : $target.'?'.$query, 301);
    }
}
