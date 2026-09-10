<?php

namespace App\Services\Registry;

use App\Models\Group;
use App\Models\Organization;

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

    /**
     * The org-level endpoint's path prefix: `/o/{slug}`, absolute like pathFor() — no scheme,
     * no host, so the two slugs of a single-registry address and the one slug of the
     * org-wide address are built the same way.
     *
     * App\Http\Controllers\Registry\ResolvesRegistryPackage::registryPathPrefixForOrganization()
     * states the identical literal for the org endpoint's own responses; its doc comment
     * names this exact method as the shared builder to introduce once a second caller
     * needed the shape. SetupSnippetBuilder::forOrganization() is that caller.
     */
    public function orgPath(Organization $organization): string
    {
        return '/o/'.$organization->slug;
    }

    /** Path prefix for a specific access path: empty for a custom domain, else path(). */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : $this->path($group);
    }

    /**
     * The host a Docker client addresses — `docker login <this>`.
     *
     * Never null, and that is the change ResolveOciContext made: the OCI Distribution Spec
     * puts `/v2/` at the root of a host, so a registry used to need a domain of its own
     * before any Docker client could reach it. It no longer does. The instance's own host
     * serves `/v2/` too, and the organization and registry slugs ride along as the leading
     * segments of the repository name (see dockerRepositoryPrefix() below). A custom domain
     * is now the SHORTER address, not the price of entry.
     *
     * On the instance-host branch the port is kept, unlike host(): an image reference is
     * written host-and-port or not at all, and an instance published on a non-default port is
     * the ordinary development case. The custom-domain branch delegates to host() and would
     * therefore DROP a port — harmless only because the `domains` column holds a bare
     * hostname with no port in it and base() hardcodes `https://`, so a custom domain is
     * :443 by assumption. A domain row that ever carries a port would have to be handled
     * here, not left to host().
     */
    public function dockerHost(Group $group): string
    {
        return $group->domains->isNotEmpty() ? $this->host($group) : $this->instanceDockerHost();
    }

    /**
     * The Docker host every domain-less registry's dockerHost() resolves to — the instance's
     * own authority, port included. Stated once here so SetupSnippetBuilder::forOrganization()
     * (which prints ONE shared Docker host for every registry in an organization, not a
     * per-registry fact) can ask for it without going through a particular Group whose own
     * domain state would be irrelevant to the answer.
     */
    public function instanceDockerHost(): string
    {
        return $this->authority($this->origin());
    }

    /**
     * What a repository name carries in front of it on that host: nothing on a custom
     * domain, `{organization}/{registry}/` on the instance host.
     *
     * This is the ONE statement of the writing end of ResolveOciContext's split — that
     * middleware strips exactly these two leading segments back off again — so the two forms
     * cannot drift. Note it is NOT path(): `/r/…` is the Composer/npm/Python address, and
     * neither the `/r` nor a leading slash belongs in an image reference.
     */
    public function dockerRepositoryPrefix(Group $group): string
    {
        if ($group->domains->isNotEmpty()) {
            return '';
        }

        return $group->organization->slug.'/'.$group->slug.'/';
    }

    /**
     * Everything a `docker pull` writes before the repository name, without a trailing
     * slash — host and namespace as one string, for the callers that only ever concatenate
     * the two (SetupSnippetBuilder::installCommand() among them).
     */
    public function dockerImagePrefix(Group $group): string
    {
        return rtrim($this->dockerHost($group).'/'.$this->dockerRepositoryPrefix($group), '/');
    }

    /** Host plus `:port` when the URL names one — parse_url's PHP_URL_HOST drops it. */
    private function authority(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $port === null ? $host : $host.':'.$port;
    }
}
