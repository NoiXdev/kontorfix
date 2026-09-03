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

        return rtrim((string) config('app.url'), '/').$this->path($group);
    }

    /** Host part for auth.json / .npmrc (without scheme, without path). */
    public function host(Group $group): string
    {
        return (string) parse_url($this->base($group), PHP_URL_HOST);
    }

    /**
     * The registry's path prefix, independent of how it is being addressed. This is the
     * one statement of the URL form: every other site that needs it asks here, so a change
     * to the form is a change to one line.
     */
    public function path(Group $group): string
    {
        return '/r/'.$group->slug;
    }

    /** Path prefix for a specific access path: empty for a custom domain, else path(). */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : $this->path($group);
    }
}
