import { router } from '@inertiajs/vue3';
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';

export interface ColumnDef<T> {
    key: string;
    label: string;
    sortable?: boolean;
    sortAs?: 'string' | 'number' | 'date';
    // Escape hatch for a value that is not simply row[key] — a nested relation name,
    // or a count. Returning null puts the row at the end regardless of direction.
    sortValue?: (row: T) => string | number | null;
}

export interface FilterDef<T> {
    label: string;
    options: { value: string; label: string }[];
    match: (row: T, value: string) => boolean;
}

export interface TableState<T> {
    visibleRows: ComputedRef<T[]>;
    sortKey: Ref<string | null>;
    sortDirection: Ref<'asc' | 'desc'>;
    search: Ref<string>;
    filterValues: Record<string, Ref<string>>;
    hasActiveFilters: ComputedRef<boolean>;
    matchCount: ComputedRef<number>;
    totalCount: ComputedRef<number>;
    toggleSort: (key: string) => void;
    reset: () => void;
}

interface Options<T> {
    rows: () => T[];
    columns: ColumnDef<T>[];
    searchKeys?: (keyof T)[];
    filters?: Record<string, FilterDef<T>>;
    // Prefix for the query-string keys. Required when one page hosts two tables,
    // so sorting one does not disturb the other.
    prefix?: string;
    mode?: 'client' | 'server';
    defaultSort?: { key: string; direction: 'asc' | 'desc' };
}

function readParam(name: string): string {
    if (typeof window === 'undefined') return '';
    return new URLSearchParams(window.location.search).get(name) ?? '';
}

export function useTableState<T extends Record<string, unknown>>(options: Options<T>): TableState<T> {
    const mode = options.mode ?? 'client';
    const p = options.prefix ? `${options.prefix}_` : '';

    const sortKey = ref<string | null>(readParam(`${p}sort`) || options.defaultSort?.key || null);
    const sortDirection = ref<'asc' | 'desc'>(
        (readParam(`${p}direction`) as 'asc' | 'desc') || options.defaultSort?.direction || 'asc',
    );
    const search = ref(readParam(`${p}q`));

    const filterValues: Record<string, Ref<string>> = {};
    for (const name of Object.keys(options.filters ?? {})) {
        filterValues[name] = ref(readParam(`${p}${name}`));
    }

    // Start from what is already in the URL and overwrite only this table's own keys.
    // Replacing the query wholesale would be a bug with two visible faces: on a page that
    // hosts two tables, sorting one would erase the other's state, and on admin/packages it
    // would drop the q/type/status/group filters the page manages itself.
    function currentQuery(): Record<string, string | undefined> {
        const query: Record<string, string | undefined> = {};
        if (typeof window !== 'undefined') {
            for (const [key, value] of new URLSearchParams(window.location.search)) {
                query[key] = value;
            }
        }

        // A sort or filter change means the current page number no longer points at the
        // same rows, so paging restarts rather than landing somewhere arbitrary.
        delete query.page;

        query[`${p}sort`] = sortKey.value ?? undefined;
        query[`${p}direction`] = sortKey.value ? sortDirection.value : undefined;
        query[`${p}q`] = search.value || undefined;
        for (const [name, value] of Object.entries(filterValues)) {
            query[`${p}${name}`] = value.value || undefined;
        }
        return query;
    }

    // Mirror state into the URL so a sorted, filtered view is shareable and survives a
    // reload. In server mode this is also what fetches the data. Same options as the
    // existing admin/packages filter bar, so both behave identically.
    function syncUrl(): void {
        router.get(window.location.pathname, currentQuery(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    let debounceTimer: ReturnType<typeof setTimeout> | undefined;
    watch(search, () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(syncUrl, 250);
    });
    watch([sortKey, sortDirection, ...Object.values(filterValues)], syncUrl);

    const columnByKey = new Map(options.columns.map((c) => [c.key, c]));

    function valueFor(row: T, key: string): string | number | null {
        const column = columnByKey.get(key);
        if (column?.sortValue) return column.sortValue(row);
        const raw = row[key];
        if (raw === null || raw === undefined || raw === '') return null;
        if (typeof raw === 'number') return raw;
        return String(raw);
    }

    const filtered = computed(() => {
        let rows = options.rows();

        const needle = search.value.trim().toLowerCase();
        if (needle !== '' && options.searchKeys?.length) {
            rows = rows.filter((row) =>
                options.searchKeys!.some((key) => String(row[key] ?? '').toLowerCase().includes(needle)),
            );
        }

        for (const [name, def] of Object.entries(options.filters ?? {})) {
            const value = filterValues[name].value;
            if (value !== '') rows = rows.filter((row) => def.match(row, value));
        }

        return rows;
    });

    const visibleRows = computed(() => {
        // Server mode already receives the rows filtered and ordered; re-doing it here
        // would sort only the current page, which looks like a bug rather than a limit.
        if (mode === 'server') return options.rows();

        const rows = [...filtered.value];
        const key = sortKey.value;
        if (!key) return rows;

        const column = columnByKey.get(key);
        if (column && column.sortable === false) return rows;

        const factor = sortDirection.value === 'asc' ? 1 : -1;

        return rows.sort((a, b) => {
            const left = valueFor(a, key);
            const right = valueFor(b, key);

            // Nulls last in BOTH directions, so reversing never surfaces a screen of dashes.
            if (left === null && right === null) return 0;
            if (left === null) return 1;
            if (right === null) return -1;

            if (typeof left === 'number' && typeof right === 'number') {
                return (left - right) * factor;
            }
            if (column?.sortAs === 'date') {
                return (Date.parse(String(left)) - Date.parse(String(right))) * factor;
            }
            return String(left).localeCompare(String(right), 'de', { sensitivity: 'base' }) * factor;
        });
    });

    function toggleSort(key: string): void {
        const column = columnByKey.get(key);
        if (column?.sortable === false) return;

        if (sortKey.value === key) {
            sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
            return;
        }
        sortKey.value = key;
        sortDirection.value = 'asc';
    }

    function reset(): void {
        search.value = '';
        for (const value of Object.values(filterValues)) value.value = '';
    }

    const hasActiveFilters = computed(
        () => search.value !== '' || Object.values(filterValues).some((v) => v.value !== ''),
    );

    return {
        visibleRows,
        sortKey,
        sortDirection,
        search,
        filterValues,
        hasActiveFilters,
        matchCount: computed(() => (mode === 'server' ? options.rows().length : filtered.value.length)),
        totalCount: computed(() => options.rows().length),
        toggleSort,
        reset,
    };
}
