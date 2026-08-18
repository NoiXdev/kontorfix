import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTableState, type ColumnDef, type FilterDef } from './useTableState';

// useTableState calls router.get() from a `watch` every time sort/search/filter state
// changes, to mirror it into the URL. Real navigation has no place in a unit test, so the
// whole module is replaced with a spy. This is the only thing stubbed beyond `window`:
// the sort/filter/URL-building logic below all runs for real.
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
}));

import { router } from '@inertiajs/vue3';

function stubLocation(pathname: string, search: string): void {
    vi.stubGlobal('window', { location: { pathname, search } });
}

beforeEach(() => {
    vi.clearAllMocks();
    stubLocation('/test', '');
});

interface Row {
    id: number;
    name: string;
    email?: string | null;
    score?: number | null;
    joinedAt?: string | null;
    team?: string;
}

const nameColumn: ColumnDef<Row> = { key: 'name', label: 'Name' };
const emailColumn: ColumnDef<Row> = { key: 'email', label: 'E-Mail' };
const scoreColumn: ColumnDef<Row> = { key: 'score', label: 'Score' };
const joinedColumn: ColumnDef<Row> = { key: 'joinedAt', label: 'Joined', sortAs: 'date' };
const lockedColumn: ColumnDef<Row> = { key: 'locked', label: 'Locked', sortable: false };

describe('sorting: empty/null/undefined values sort last', () => {
    // Deliberately mixes all three "missing" shapes into one column: valueFor() folds
    // null, undefined, and '' into the same "missing" sentinel, so all three must end up
    // at the tail together, not just whichever one a narrower test would have picked.
    const rows: Row[] = [
        { id: 1, name: 'Bob', email: 'bob@example.com' },
        { id: 2, name: 'NullMail', email: null },
        { id: 3, name: 'Alice', email: 'alice@example.com' },
        { id: 4, name: 'EmptyMail', email: '' },
        { id: 5, name: 'UndefinedMail', email: undefined },
        { id: 6, name: 'Chris', email: 'chris@example.com' },
    ];

    it('puts them last in ascending order', () => {
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, emailColumn] });
        table.toggleSort('email');
        expect(table.visibleRows.value.map((r) => r.name)).toEqual([
            'Alice',
            'Bob',
            'Chris',
            'NullMail',
            'EmptyMail',
            'UndefinedMail',
        ]);
    });

    it('puts them STILL last in descending order (the easy-to-get-wrong case)', () => {
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, emailColumn] });
        table.toggleSort('email'); // asc
        table.toggleSort('email'); // desc
        expect(table.visibleRows.value.map((r) => r.name)).toEqual([
            'Chris',
            'Bob',
            'Alice',
            'NullMail',
            'EmptyMail',
            'UndefinedMail',
        ]);
    });
});

describe('sorting: string comparison', () => {
    it('is case- and diacritic-insensitive, so "Ärger" and "arger" land adjacent', () => {
        const rows: Row[] = [
            { id: 1, name: 'Bob' },
            { id: 2, name: 'Ärger' },
            { id: 3, name: 'arger' },
        ];
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn] });
        table.toggleSort('name');
        // Both "Ärger" and "arger" collapse to the same base letter under
        // sensitivity: 'base', so a stable sort keeps them in their original relative
        // order, both ahead of the clearly-different "Bob".
        expect(table.visibleRows.value.map((r) => r.name)).toEqual(['Ärger', 'arger', 'Bob']);
    });
});

describe('sorting: numeric columns', () => {
    it('compares numerically, so 10 sorts after 9 rather than before it', () => {
        const rows: Row[] = [
            { id: 1, name: 'A', score: 10 },
            { id: 2, name: 'B', score: 9 },
            { id: 3, name: 'C', score: 2 },
        ];
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, scoreColumn] });
        table.toggleSort('score');
        expect(table.visibleRows.value.map((r) => r.score)).toEqual([2, 9, 10]);
    });
});

describe('sorting: sortAs "date"', () => {
    it('compares chronologically, not lexically', () => {
        // Lexically, "1/15/2024" < "12/1/2023" < "3/1/2024" (plain string compare).
        // Chronologically, Dec 2023 < Jan 2024 < Mar 2024 — the opposite order for the
        // first two. Only date-aware parsing gets this right.
        const rows: Row[] = [
            { id: 1, name: 'A', joinedAt: '1/15/2024' },
            { id: 2, name: 'B', joinedAt: '12/1/2023' },
            { id: 3, name: 'C', joinedAt: '3/1/2024' },
        ];
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, joinedColumn] });
        table.toggleSort('joinedAt');
        expect(table.visibleRows.value.map((r) => r.id)).toEqual([2, 1, 3]);
    });
});

describe('filtering happens before sorting', () => {
    it('excludes non-matching rows from visibleRows, sorted among what remains', () => {
        const rows: Row[] = [
            { id: 1, name: 'Alice', team: 'red' },
            { id: 2, name: 'Bob', team: 'blue' },
            { id: 3, name: 'Chris', team: 'red' },
        ];
        const filters: Record<string, FilterDef<Row>> = {
            team: {
                label: 'Team',
                options: [
                    { value: 'red', label: 'Red' },
                    { value: 'blue', label: 'Blue' },
                ],
                match: (row, value) => row.team === value,
            },
        };
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn], filters });
        table.setFilter('team', 'red');
        table.toggleSort('name');

        expect(table.visibleRows.value.map((r) => r.id)).toEqual([1, 3]); // Bob excluded
        expect(table.matchCount.value).toBe(2);
        expect(table.totalCount.value).toBe(3);
    });
});

describe('toggleSort', () => {
    const rows: Row[] = [
        { id: 1, name: 'A', score: 1 },
        { id: 2, name: 'B', score: 2 },
    ];

    it('flips direction when the same column is toggled again', () => {
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, scoreColumn] });
        table.toggleSort('name');
        expect(table.sortDirection.value).toBe('asc');
        table.toggleSort('name');
        expect(table.sortDirection.value).toBe('desc');
        table.toggleSort('name');
        expect(table.sortDirection.value).toBe('asc');
    });

    it('starts ascending when switching to a different column', () => {
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, scoreColumn] });
        table.toggleSort('name');
        table.toggleSort('name'); // now desc on name
        table.toggleSort('score'); // a different column
        expect(table.sortKey.value).toBe('score');
        expect(table.sortDirection.value).toBe('asc');
    });

    it('does nothing for a column declared sortable: false', () => {
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn, lockedColumn] });
        expect(table.sortKey.value).toBeNull();
        table.toggleSort('locked');
        expect(table.sortKey.value).toBeNull();
    });
});

describe('URL sync (currentQuery, via the sort/filter watcher)', () => {
    it('preserves query keys it does not own and overwrites only its own prefixed keys', async () => {
        stubLocation('/admin/users', '?foreign=42&other_table_q=abc&users_sort=stale');
        const rows: Row[] = [{ id: 1, name: 'A' }];
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn], prefix: 'users' });

        table.toggleSort('name');
        await nextTick();

        expect(router.get).toHaveBeenCalled();
        const [path, query] = vi.mocked(router.get).mock.calls.at(-1)!;
        expect(path).toBe('/admin/users');
        expect(query).toMatchObject({
            foreign: '42',
            other_table_q: 'abc',
            users_sort: 'name',
            users_direction: 'asc',
        });
    });

    it('drops the page query param on a state change', async () => {
        stubLocation('/admin/users', '?page=5');
        const rows: Row[] = [{ id: 1, name: 'A' }];
        const table = useTableState<Row>({ rows: () => rows, columns: [nameColumn] });

        table.toggleSort('name');
        await nextTick();

        const [, query] = vi.mocked(router.get).mock.calls.at(-1)!;
        expect(query).not.toHaveProperty('page');
    });
});
