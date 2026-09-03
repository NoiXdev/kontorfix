import { createSyncStatusReconciler, type SyncStatus, type SyncStatusSnapshot } from '@/lib/syncStatusPoll';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

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
 */
export function usePackageSyncStatus(
    packageId: string,
    initial: SyncStatusSnapshot,
): { status: Ref<SyncStatus>; error: Ref<string | null>; apply: (snapshot: SyncStatusSnapshot) => void } {
    const status = ref<SyncStatus>(initial.status);
    const error = ref<string | null>(initial.error);

    function apply(snapshot: SyncStatusSnapshot): void {
        status.value = snapshot.status;
        error.value = snapshot.error;
    }

    const reconciler = createSyncStatusReconciler({
        current: () => status.value,
        read: async (): Promise<SyncStatusSnapshot> => {
            const response = await fetch(route('admin.packages.sync-status', packageId), {
                // `Accept: application/json` also makes an expired session answer 401
                // instead of redirecting to the login page as HTML.
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`sync-status: ${response.status}`);
            }

            const body = (await response.json()) as { status: SyncStatus; error: string | null };

            return { status: body.status, error: body.error ?? null };
        },
        apply,
    });

    onMounted(() => reconciler.start());
    onBeforeUnmount(() => reconciler.stop());

    return { status, error, apply };
}
