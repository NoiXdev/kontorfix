import type { NavItem } from '@/types';
import { describe, expect, it } from 'vitest';
import { isNavItemActive, isWithin, normalisePath, sectionHoldsCurrentPage } from './navPath';

describe('normalisePath', () => {
    it('drops the query string, which the listings now write to', () => {
        expect(normalisePath('/admin/packages?sort=name&direction=desc')).toBe('/admin/packages');
    });

    it('drops the fragment', () => {
        expect(normalisePath('/admin/groups#members')).toBe('/admin/groups');
    });

    it('drops a trailing slash so the two spellings compare equal', () => {
        expect(normalisePath('/admin/users/')).toBe('/admin/users');
    });

    it('keeps root as root rather than collapsing it to an empty string', () => {
        expect(normalisePath('/')).toBe('/');
    });
});

describe('isWithin', () => {
    it('matches the section entry itself', () => {
        expect(isWithin('/admin/packages', '/admin/packages')).toBe(true);
    });

    it('matches a detail page nested beneath the entry', () => {
        expect(isWithin('/admin/packages/01a01537-bff4-706f-9c0d-4375e39828a5', '/admin/packages')).toBe(true);
    });

    it('matches a sorted listing, whose URL carries a query string', () => {
        expect(isWithin('/admin/packages?sort=name', '/admin/packages')).toBe(true);
    });

    it('does NOT match a sibling path that merely shares a prefix', () => {
        // The case a plain startsWith gets wrong.
        expect(isWithin('/dashboard-archive', '/dashboard')).toBe(false);
        expect(isWithin('/admin/packages-legacy', '/admin/packages')).toBe(false);
    });

    it('does not treat root as a prefix of every page', () => {
        expect(isWithin('/admin/users', '/')).toBe(false);
        expect(isWithin('/', '/')).toBe(true);
    });

    it('does not match an unrelated section', () => {
        expect(isWithin('/admin/users', '/admin/packages')).toBe(false);
    });
});

// System → E-Mail/Storage: "System" is a NavItem with children rather than a bare link, so
// both the per-item active highlight and the section's "open by default" rule have to reach
// one level down into `item.children` — the shape neither predicate had to consider before.
const systemItem: NavItem = {
    title: 'System',
    href: '/admin/system',
    children: [
        { title: 'E-Mail', href: '/admin/mail' },
        { title: 'Storage', href: '/admin/storage' },
    ],
};

describe('isNavItemActive', () => {
    it('matches the item’s own page', () => {
        expect(isNavItemActive('/admin/system', systemItem)).toBe(true);
    });

    it('matches a child’s page, so the parent highlights while a child page is open', () => {
        expect(isNavItemActive('/admin/mail', systemItem)).toBe(true);
        expect(isNavItemActive('/admin/storage', systemItem)).toBe(true);
    });

    it('does not match an unrelated page', () => {
        expect(isNavItemActive('/admin/users', systemItem)).toBe(false);
    });

    it('does not match a lookalike page a plain prefix check would', () => {
        expect(isNavItemActive('/admin/mail-templates', systemItem)).toBe(false);
    });

    it('matches a plain item (no children) exactly, same as before', () => {
        expect(isNavItemActive('/admin/packages', { title: 'Pakete', href: '/admin/packages' })).toBe(true);
        expect(isNavItemActive('/admin/packages/01a0', { title: 'Pakete', href: '/admin/packages' })).toBe(false);
    });
});

describe('sectionHoldsCurrentPage', () => {
    const items: NavItem[] = [
        { title: 'System', href: '/admin/system', children: [{ title: 'E-Mail', href: '/admin/mail' }, { title: 'Storage', href: '/admin/storage' }] },
        { title: 'Speicherbereinigung', href: '/admin/oci/sweeper' },
        { title: 'Aktivität', href: '/admin/activity' },
    ];

    it('opens the section for its own item pages, as before', () => {
        expect(sectionHoldsCurrentPage('/admin/activity', items)).toBe(true);
    });

    it('opens the section when the current page is a child of a nested item', () => {
        expect(sectionHoldsCurrentPage('/admin/mail', items)).toBe(true);
        expect(sectionHoldsCurrentPage('/admin/storage', items)).toBe(true);
    });

    it('opens for a detail page nested beneath a top-level item', () => {
        expect(sectionHoldsCurrentPage('/admin/activity/42', items)).toBe(true);
    });

    it('stays closed for an unrelated page', () => {
        expect(sectionHoldsCurrentPage('/admin/packages', items)).toBe(false);
    });
});
