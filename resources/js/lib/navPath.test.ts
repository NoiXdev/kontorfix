import { describe, expect, it } from 'vitest';
import { isWithin, normalisePath } from './navPath';

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
