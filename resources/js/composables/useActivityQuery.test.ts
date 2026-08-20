import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { useActivityQuery, type ActivityFilters } from './useActivityQuery';

// Same treatment as useTableState.test.ts: the only thing stubbed is the navigation itself,
// so the parameters asserted below are the ones the real code builds.
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
}));

import { router } from '@inertiajs/vue3';

function stubLocation(search: string): void {
    vi.stubGlobal('window', { location: { pathname: '/admin/activity', search } });
}

function filters(overrides: Partial<ActivityFilters> = {}): ActivityFilters {
    return {
        log: null,
        subject_type: null,
        subject_id: null,
        causer: null,
        sort: null,
        direction: 'desc',
        per_page: 50,
        ...overrides,
    };
}

function lastCall(): [string, Record<string, string>, Record<string, unknown>] {
    const call = vi.mocked(router.get).mock.calls.at(-1);
    expect(call, 'router.get was never called — the control reaches nothing').toBeDefined();
    return call as [string, Record<string, string>, Record<string, unknown>];
}

beforeEach(() => {
    vi.clearAllMocks();
    stubLocation('');
});

describe('the direction toggle', () => {
    it('sends the sort column along with the direction, not the direction alone', () => {
        // ActivityController only applies `direction` when `sort` names a whitelisted
        // column; on its own it falls through to the default order and the list does not
        // move. This is the assertion that catches a toggle which looks right and does
        // nothing — it is the one failure mode a rendered-control check cannot see.
        const query = useActivityQuery(() => filters({ direction: 'desc' }));

        query.toggleDirection();

        const [path, params] = lastCall();
        expect(path).toBe('/admin/activity');
        expect(params).toEqual({ sort: 'created_at', direction: 'asc' });
    });

    it('flips back to newest first on a second press', () => {
        const query = useActivityQuery(() => filters({ direction: 'desc' }));

        query.toggleDirection();
        query.toggleDirection();

        expect(lastCall()[1]).toEqual({ sort: 'created_at', direction: 'desc' });
        expect(query.direction.value).toBe('desc');
    });

    it('starts from the direction the server reported, not from a hardcoded default', () => {
        // The server reports the direction it actually ordered by, so a list arriving as
        // oldest-first must toggle to newest-first — not to oldest-first again.
        const query = useActivityQuery(() => filters({ sort: 'created_at', direction: 'asc' }));

        expect(query.direction.value).toBe('asc');
        query.toggleDirection();

        expect(lastCall()[1].direction).toBe('desc');
    });

    it('keeps the scoping and the page size that are already in the URL', () => {
        stubLocation('?subject_type=Package&subject_id=abc&per_page=100');
        const query = useActivityQuery(() => filters({ per_page: 100 }));

        query.toggleDirection();

        expect(lastCall()[1]).toEqual({
            subject_type: 'Package',
            subject_id: 'abc',
            per_page: '100',
            sort: 'created_at',
            direction: 'asc',
        });
    });
});

describe('the page-size selector', () => {
    it('writes the size as the query parameter the controller validates', () => {
        const query = useActivityQuery(() => filters());

        query.setPerPage(100);

        expect(lastCall()[1]).toEqual({ per_page: '100' });
        expect(query.perPage.value).toBe(100);
    });

    it('restarts paging, because page 4 of 50 is not page 4 of 25', () => {
        stubLocation('?page=4&per_page=50');
        const query = useActivityQuery(() => filters({ per_page: 50 }));

        query.setPerPage(25);

        expect(lastCall()[1]).not.toHaveProperty('page');
        expect(lastCall()[1].per_page).toBe('25');
    });

    it('leaves an active sort alone', () => {
        stubLocation('?sort=created_at&direction=asc');
        const query = useActivityQuery(() => filters({ sort: 'created_at', direction: 'asc' }));

        query.setPerPage(25);

        expect(lastCall()[1]).toEqual({ sort: 'created_at', direction: 'asc', per_page: '25' });
    });
});

describe('the log-name filter', () => {
    it('keeps the sort and the page size, which it does not own', () => {
        // The bug this replaces: the filter bar rebuilt the query from its own refs and had
        // to read `sort` back out of the URL by hand to avoid resetting the order. A page
        // size added to that shape would have been dropped on every filter change.
        stubLocation('?sort=created_at&direction=asc&per_page=100&subject_id=abc');
        const query = useActivityQuery(() => filters({ sort: 'created_at', direction: 'asc', per_page: 100 }));

        query.setLog('package');

        expect(lastCall()[1]).toEqual({
            sort: 'created_at',
            direction: 'asc',
            per_page: '100',
            subject_id: 'abc',
            log: 'package',
        });
    });

    it('removes the parameter for "Alle Bereiche" rather than filtering on an empty name', () => {
        stubLocation('?log=user&per_page=25');
        const query = useActivityQuery(() => filters({ log: 'user', per_page: 25 }));

        query.setLog('');

        expect(lastCall()[1]).toEqual({ per_page: '25' });
    });
});

describe('clearing the subject/causer scoping', () => {
    it('removes exactly the scoping keys and keeps the rest of the view', () => {
        stubLocation('?subject_type=Package&subject_id=abc&causer=xyz&log=package&per_page=25');
        const query = useActivityQuery(() => filters({ log: 'package', per_page: 25 }));

        query.clearScope();

        expect(lastCall()[1]).toEqual({ log: 'package', per_page: '25' });
    });
});

describe('following the server', () => {
    it('adopts the page size the server actually applied', async () => {
        // The whitelist can reject what the URL asked for. The selector then has to show
        // the size that was used, or it reports a page length the list does not have.
        const current = ref(filters({ per_page: 100 }));
        const query = useActivityQuery(() => current.value);
        expect(query.perPage.value).toBe(100);

        current.value = filters({ per_page: 50 });
        await nextTick();

        expect(query.perPage.value).toBe(50);
    });

    it('adopts the direction the server actually ordered by', async () => {
        const current = ref(filters({ direction: 'asc', sort: 'created_at' }));
        const query = useActivityQuery(() => current.value);

        current.value = filters({ direction: 'desc', sort: null });
        await nextTick();

        expect(query.direction.value).toBe('desc');
    });
});
