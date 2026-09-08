import type { NavItem } from '@/types';

/**
 * Path helpers for deciding which sidebar section holds the page you are on.
 *
 * Extracted from the component so the boundary cases can be tested: there is no frontend
 * runner for components, and the in-app browser cannot load this application, so logic left
 * inside a `.vue` file is logic nothing verifies.
 */

/**
 * Reduce a URL to a comparable path: no query string, no fragment, no trailing slash.
 *
 * Inertia's `page.url` carries the query string, and the listings put their sort and filter
 * state there. Comparing the raw value meant `/admin/packages?sort=name` stopped matching
 * `/admin/packages`, so a section quietly failed to recognise itself as active.
 */
export function normalisePath(url: string): string {
    const path = url.split('?')[0]?.split('#')[0] ?? '/';
    const trimmed = path.replace(/\/+$/, '');

    return trimmed === '' ? '/' : trimmed;
}

/**
 * Is `current` the same page as `href`, or one nested beneath it?
 *
 * The comparison runs on segment boundaries, so `/admin/packages/01a0…` counts as living
 * under `/admin/packages`, while `/dashboard-archive` does NOT count as living under
 * `/dashboard` — a plain `startsWith` would get that wrong.
 */
export function isWithin(current: string, href: string): boolean {
    const a = normalisePath(current);
    const b = normalisePath(href);

    // Root is only ever itself; treating it as a prefix would match every page.
    if (b === '/') {
        return a === '/';
    }

    return a === b || a.startsWith(`${b}/`);
}

/**
 * Is `item` the one to highlight for the current page — its own page, or (for an item with
 * a sub-menu, e.g. "System" → E-Mail/Storage) one of its children's?
 *
 * An exact match against `item.href`/`child.href`, deliberately not `isWithin()`: this is
 * the same strict comparison NavMain.vue always used for its own per-item highlight (as
 * opposed to the section-level "open by default" rule below, which does use prefix
 * matching) — extended one level down rather than changed.
 */
export function isNavItemActive(current: string, item: NavItem): boolean {
    if (current === item.href) {
        return true;
    }

    return (item.children ?? []).some((child) => child.href === current);
}

/**
 * Does the section holding `items` cover the current page — an item's own page (or a
 * detail page nested beneath it), or, for an item with a sub-menu, one of its children's?
 *
 * NavMain.vue's "the section you're inside opens itself" rule, extended one level down:
 * moving E-Mail/Storage out of the section's own item list and into "System"'s `children`
 * must not stop visiting /admin/mail from opening the "System" section.
 */
export function sectionHoldsCurrentPage(current: string, items: NavItem[]): boolean {
    return items.some((item) => isWithin(current, item.href) || (item.children ?? []).some((child) => isWithin(current, child.href)));
}
