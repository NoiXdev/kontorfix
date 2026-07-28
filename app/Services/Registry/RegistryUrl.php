<?php

namespace App\Services\Registry;

use App\Models\Group;

class RegistryUrl
{
    /** Full base URL of the registry (without trailing slash). */
    public function base(Group $group): string
    {
        // Deterministic selection when there are multiple custom domains (otherwise order-dependent).
        $domain = $group->domains->sortBy('hostname')->first();

        if ($domain !== null) {
            return 'https://'.$domain->hostname;
        }

        return rtrim((string) config('app.url'), '/').'/r/'.$group->slug;
    }

    /** Host part for auth.json / .npmrc (without scheme, without path). */
    public function host(Group $group): string
    {
        return (string) parse_url($this->base($group), PHP_URL_HOST);
    }

    /** Path prefix: empty for a custom domain, otherwise /r/{slug}. */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : '/r/'.$group->slug;
    }
}
