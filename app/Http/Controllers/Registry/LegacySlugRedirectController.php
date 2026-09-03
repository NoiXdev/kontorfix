<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Services\Registry\LegacySlugRedirector;
use App\Services\Registry\RegistryUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answers the pre-org-scoped /r/{slug}/... URLs that live in customers' composer.json,
 * .npmrc and pip.conf. Composer, npm and pip all follow redirects, so an existing client
 * keeps working on a read and sees the canonical URL in its logs.
 *
 * Registered AFTER the canonical route: Laravel matches the first route that fits, so
 * /r/{org}/{group} wins whenever a request's shape could satisfy both. See
 * ResolveRegistryContext for the other place a legacy URL can land: when its shape
 * satisfies the canonical route syntactically (pip's /simple/{project} is the concrete
 * case), this route never runs at all.
 *
 * A slug shared by two organizations is not ambiguous here: LegacySlugRedirector matches
 * `groups.legacy_slug`, which is unique and populated only for the registry that already
 * held the slug when the instance was upgraded, so a shared slug always 301s to that
 * incumbent rather than to whichever organization later claimed the same live `slug`.
 */
class LegacySlugRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        RegistryUrl $urls,
        LegacySlugRedirector $redirector,
        string $groupSlug,
    ): RedirectResponse {
        $group = $redirector->resolve($groupSlug);
        abort_if($group === null, 404);

        return $redirector->respond($redirector->target($group, $request, $urls, 2), $request);
    }
}
