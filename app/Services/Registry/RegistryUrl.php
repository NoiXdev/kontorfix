<?php

namespace App\Services\Registry;

use App\Models\Group;

class RegistryUrl
{
    /**
     * Placeholders the console substitutes into template() / pattern(). They exist so a
     * mask that has to show a URL it cannot yet build — a registry being created, or a slug
     * being changed — still gets the URL form from here rather than restating it in Vue.
     */
    public const ORGANIZATION_PLACEHOLDER = '{organization}';

    public const REGISTRY_PLACEHOLDER = '{registry}';

    /** Full base URL of the registry (without trailing slash). */
    public function base(Group $group): string
    {
        // Deterministic selection when there are multiple custom domains (otherwise order-dependent).
        $domain = $group->domains->sortBy('hostname')->first();

        if ($domain !== null) {
            return 'https://'.$domain->hostname;
        }

        return $this->canonical($group);
    }

    /**
     * The registry's own address on this instance, ignoring any custom domain.
     *
     * base() prefers a custom domain, which is right for the setup snippets but wrong for
     * anything that talks *about* the slug: a custom-domain registry's base URL does not
     * contain the slug at all, so a slug-change confirmation built from it would show the
     * same URL twice and warn about nothing.
     */
    public function canonical(Group $group): string
    {
        return $this->origin().$this->path($group);
    }

    /** The instance origin the canonical registry URLs hang off (no trailing slash). */
    public function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
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
        return $this->pathFor($group->organization->slug, $group->slug);
    }

    /**
     * The URL form itself. Everything above and every preview below goes through this one
     * line, so the two slugs may equally be real values or placeholders.
     */
    public function pathFor(string $organizationSlug, string $registrySlug): string
    {
        // The organization scopes the slug, so it is part of the address.
        return '/r/'.$organizationSlug.'/'.$registrySlug;
    }

    /**
     * The canonical URL with only the registry slug left open, for the console's "and this
     * is what it becomes" confirmation before a slug change. The console substitutes
     * REGISTRY_PLACEHOLDER; it never assembles a URL of its own.
     */
    public function pattern(Group $group): string
    {
        return $this->origin().$this->pathFor($group->organization->slug, self::REGISTRY_PLACEHOLDER);
    }

    /**
     * The bare URL form with both slugs left open, for the masks that preview a registry
     * which does not exist yet — the create sheet and the setup wizard, neither of which
     * has a Group to ask about.
     */
    public function template(): string
    {
        return $this->pathFor(self::ORGANIZATION_PLACEHOLDER, self::REGISTRY_PLACEHOLDER);
    }

    /** Path prefix for a specific access path: empty for a custom domain, else path(). */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : $this->path($group);
    }
}
