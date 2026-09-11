import { createSyncStatusReconciler, parseSyncStatusResponse, type SyncStatus, type SyncStatusSnapshot } from '@/lib/syncStatusPoll';
import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

export interface PackageSyncStatus {
    /** The status the badge renders. */
    status: Ref<SyncStatus>;
    /** The failure text shown next to a failed badge. */
    error: Ref<string | null>;
    /**
     * True once the page has stopped trying to keep the status current while it is still
     * non-terminal. The page says so rather than leaving a badge that has quietly frozen.
     */
    stale: Ref<boolean>;
    /** Applies an update from the broadcast listener. */
    apply: (snapshot: SyncStatusSnapshot) => void;
    /** Begins reconciling. Called from `onMounted` by the composable below. */
    start: () => void;
    /** Stops reconciling and disposes the re-seed watcher. */
    stop: () => void;
}

/**
 * The lifecycle-free core, so it can be unit-tested.
 *
 * This repo's vitest runs in a `node` environment with no component-rendering harness, so
 * anything behind `onMounted` is untestable. `useTableState` splits the same way: the
 * logic uses `ref`/`watch` only, and the Vue lifecycle stays in the thin wrapper below.
 */
export function createPackageSyncStatus(options: {
    /** Reads the server-rendered prop. Must be reactive — it is watched, see below. */
    seed: () => SyncStatusSnapshot;
    read: () => Promise<SyncStatusSnapshot>;
    delays?: readonly number[];
    /**
     * Whether this viewer's browser may poll the server at all. Defaults to `true`.
     *
     * A receiving customer on a shared package's page has `can_manage_assignments: false`,
     * and `admin.packages.syncStatus` keeps `assertCanTouchPackage()` regardless — it was
     * never widened alongside the page's own viewing guard. Polling anyway means every
     * scheduled `read()` 403s, which `syncStatusPoll`'s reconciler treats as "unknown, try
     * again", burning the whole attempt budget and ending in a false "veraltet" give-up
     * notice for a viewer who could never have fixed it by reloading. The badge still shows
     * the server-rendered snapshot — it simply never tries to freshen it — since only a
     * managing viewer can trigger "Erneut synchronisieren" in the first place.
     */
    poll?: boolean;
}): PackageSyncStatus {
    const pollEnabled = options.poll ?? true;
    const initial = options.seed();
    const status = ref<SyncStatus>(initial.status);
    const error = ref<string | null>(initial.error);
    const stale = ref(false);

    function apply(snapshot: SyncStatusSnapshot): void {
        status.value = snapshot.status;
        error.value = snapshot.error;
        // Any fresh fact means the display is current again.
        stale.value = false;
    }

    const reconciler = createSyncStatusReconciler({
        current: () => status.value,
        read: options.read,
        apply,
        onGiveUp: () => (stale.value = true),
        delays: options.delays,
    });

    /**
     * Re-seed whenever the server re-renders this page.
     *
     * Inertia sets `preserveState: true` for post/put/patch/delete, so the component is
     * *not* remounted after "Erneut synchronisieren": the props change under a component
     * that has already seeded itself once and, having seen a terminal status, already
     * stopped. Without this the badge would sit on "Synchronisiert" while the server had
     * moved the package back to `pending` — the very defect this whole mechanism exists to
     * fix, one button-click away.
     *
     * A fresh server render is authoritative, so it is applied and the reconciler is
     * re-armed unconditionally. Re-arming on a terminal status costs nothing: `restart()`
     * stands down without sending a request.
     */
    const stopWatching = watch(
        () => [options.seed().status, options.seed().error] as const,
        ([nextStatus, nextError]) => {
            apply({ status: nextStatus, error: nextError });

            if (pollEnabled) {
                reconciler.restart();
            }
        },
    );

    return {
        status,
        error,
        stale,
        apply,
        start: () => {
            if (pollEnabled) {
                reconciler.start();
            }
        },
        stop: () => {
            reconciler.stop();
            stopWatching();
        },
    };
}

/** Reads the package's sync status back from the server. Throws on anything unexpected. */
export async function fetchSyncStatus(packageId: string): Promise<SyncStatusSnapshot> {
    const response = await fetch(route('admin.packages.sync-status', packageId), {
        // `Accept: application/json` also makes an expired session answer 401 instead of
        // redirecting to the login page as HTML.
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`sync-status: ${response.status}`);
    }

    return parseSyncStatusResponse(await response.json());
}

/**
 * The sync status the package detail page displays, kept correct from two directions.
 *
 * `useOperatorChannel` pushes `PackageSynced` / `PackageSyncFailed` in, which is the fast
 * path — but only for a browser that managed to subscribe before the job finished, and
 * only for a super-admin on a build that had a Reverb key. The reconciler in
 * `@/lib/syncStatusPoll` covers everything else by reading the status back from the server
 * with a backoff until it turns terminal; the scheduling rules and their tests live there,
 * this file is the Vue and `fetch` wiring around them.
 *
 * Both write the same refs, so whichever answers first wins and a later broadcast is still
 * applied on top.
 *
 * @param seed reads the server-rendered prop; it is watched, so pass a getter over props
 *             rather than a snapshot taken at setup time.
 * @param poll whether to poll the server at all — see `createPackageSyncStatus()`'s own
 *             `poll` option. Defaults to `true` for every existing caller.
 */
export function usePackageSyncStatus(packageId: string, seed: () => SyncStatusSnapshot, poll: boolean = true): PackageSyncStatus {
    const core = createPackageSyncStatus({ seed, read: () => fetchSyncStatus(packageId), poll });

    onMounted(core.start);
    onBeforeUnmount(core.stop);

    return core;
}
