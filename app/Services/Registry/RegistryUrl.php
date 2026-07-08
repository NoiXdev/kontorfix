<?php

namespace App\Services\Registry;

use App\Models\Group;

class RegistryUrl
{
    /** Vollständige Basis-URL der Registry (ohne abschließenden Slash). */
    public function base(Group $group): string
    {
        // Deterministische Auswahl bei mehreren Custom-Domains (sonst reihenfolgeabhängig).
        $domain = $group->domains->sortBy('hostname')->first();

        if ($domain !== null) {
            return 'https://'.$domain->hostname;
        }

        return rtrim((string) config('app.url'), '/').'/r/'.$group->slug;
    }

    /** Host-Teil für auth.json / .npmrc (ohne Schema, ohne Pfad). */
    public function host(Group $group): string
    {
        return (string) parse_url($this->base($group), PHP_URL_HOST);
    }

    /** Pfad-Präfix: leer bei Custom-Domain, sonst /r/{slug}. */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : '/r/'.$group->slug;
    }
}
