import { mergeQuery } from '@/lib/listingQuery';
import { router } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';

export interface ActivityFilters {
    log: string | null;
    subject_type: string | null;
    subject_id: string | null;
    causer: string | null;
    sort: string | null;
    direction: 'asc' | 'desc';
    per_page: number;
}

/**
 * The one column the timeline's direction toggle sorts by. `ActivityController::SORTABLE`
 * has to contain it or the toggle writes a key the server drops on the floor; the Pest
 * cover in `ListingSortTest` asserts both directions actually reorder rows.
 */
export const ACTIVITY_SORT_COLUMN = 'created_at';

export interface ActivityQuery {
    logFilter: Ref<string>;
    direction: Ref<'asc' | 'desc'>;
    perPage: Ref<number>;
    setLog: (value: string) => void;
    toggleDirection: () => void;
    setPerPage: (size: number) => void;
    clearScope: () => void;
}

/**
 * The query-string state behind `admin/activity`: the log-name filter, the newest-first /
 * oldest-first direction, and the page size.
 *
 * It lives here rather than in the page for one reason — a control that writes the wrong
 * query key looks completely correct on screen. The page renders it, the server ignores
 * it, and the list simply does not change. Keeping the parameter-building in a module
 * lets Vitest assert the exact parameters each control emits, against the exact
 * parameters the Pest cover proves the controller honours.
 */
export function useActivityQuery(filters: () => ActivityFilters): ActivityQuery {
    const logFilter = ref(filters().log ?? '');
    const direction = ref<'asc' | 'desc'>(filters().direction);
    const perPage = ref(filters().per_page);

    // Follow the server, which has the last word: it validates the page size against a
    // whitelist and reports the direction it actually ordered by, neither of which need
    // agree with what was asked for. Without this the controls would keep displaying a
    // rejected value over a list that ignored it.
    watch(filters, (current) => {
        logFilter.value = current.log ?? '';
        direction.value = current.direction;
        perPage.value = current.per_page;
    });

    function sync(updates: Record<string, string | undefined>): void {
        router.get(window.location.pathname, mergeQuery(updates), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function setLog(value: string): void {
        logFilter.value = value;
        // Only `log` is written. Rebuilding the whole query here is what used to drop the
        // sort, and would now drop the page size as well.
        sync({ log: value || undefined });
    }

    function toggleDirection(): void {
        direction.value = direction.value === 'desc' ? 'asc' : 'desc';
        // `direction` alone is not enough: with no `sort` key the controller falls back to
        // its default order and ignores the direction entirely, so the list would not move.
        sync({ sort: ACTIVITY_SORT_COLUMN, direction: direction.value });
    }

    function setPerPage(size: number): void {
        perPage.value = size;
        sync({ per_page: String(size) });
    }

    function clearScope(): void {
        // The scoping arrives from a "Aktivität ansehen" link on a detail page, so clearing
        // it means removing those keys — not setting them to anything.
        sync({ subject_type: undefined, subject_id: undefined, causer: undefined });
    }

    return { logFilter, direction, perPage, setLog, toggleDirection, setPerPage, clearScope };
}
