<?php

namespace App\Services\Portal;

/**
 * The customer portal's address form, for the console masks that have to talk ABOUT it
 * rather than link to it.
 *
 * Everything that merely wants to go there uses `route('portal.packages.index', $slug)`.
 * This exists for the one mask that has to show an address it cannot yet build — the
 * organization slug confirmation, which needs `/c/{new-slug}` for a slug that has not been
 * saved — and it is the same arrangement, for the same reason, that
 * Registry\RegistryUrl::template() serves on that very dialog: the console substitutes a
 * placeholder and never assembles a portal path of its own.
 *
 * DERIVED FROM THE ROUTE, not from a literal. `/c/` is declared once, as the prefix in
 * routes/web.php, and a second spelling of it here would be a form that can drift from the
 * address the application actually answers on while every test still passes.
 */
final class PortalUrl
{
    /**
     * The placeholder template() leaves the organization slug open as.
     *
     * Deliberately the same token RegistryUrl uses: one dialog substitutes both templates,
     * and two spellings there would be two substitutions to keep in step.
     */
    public const ORGANIZATION_PLACEHOLDER = '{organization}';

    /**
     * A slug-shaped stand-in that template() swaps for the placeholder afterwards.
     *
     * The placeholder cannot be handed to route() directly: braces survive generation, but
     * UrlGenerator then scans the result for unfilled parameters and throws on the one it
     * has just written in. The value only has to be unambiguous within the generated path.
     */
    private const SENTINEL = 'organization-slug-placeholder';

    /** The portal's path for a given organization slug (no origin, no trailing slash). */
    public function pathFor(string $organizationSlug): string
    {
        return route('portal.packages.index', $organizationSlug, absolute: false);
    }

    /** The same form with the organization slug left open, for a slug that does not exist yet. */
    public function template(): string
    {
        return str_replace(self::SENTINEL, self::ORGANIZATION_PLACEHOLDER, $this->pathFor(self::SENTINEL));
    }
}
